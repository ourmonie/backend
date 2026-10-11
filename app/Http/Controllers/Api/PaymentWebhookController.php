<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\PaystackGateway;
use App\Services\PaymentEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Public, unauthenticated endpoints - the providers call these with no
 * session of ours. Authenticity comes from the signature, not from auth:
 *
 *   Paystack    x-paystack-signature  HMAC-SHA512 of the raw body
 *   Flutterwave verif-hash            shared secret echoed back
 *
 * A bad signature is refused before the body is parsed as anything useful,
 * so the endpoint cannot be used to plant a payload. Every response is fast
 * and 200-shaped for work that already succeeded: providers retry on any
 * non-200 for up to 72 hours, and a retry is harmless because PaymentEngine
 * is idempotent - but there is no reason to invite one.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentEngine $engine)
    {
    }

    /* ---------------------------------------------------------------------- */
    /* Paystack                                                                */
    /* ---------------------------------------------------------------------- */

    public function paystack(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        if (! GatewayConfig::enabled(GatewayConfig::PAYSTACK)) {
            return $this->reject('paystack-disabled');
        }

        if (! PaystackGateway::verifySignature($raw, $signature)) {
            Log::warning('Paystack webhook rejected: signature mismatch.');

            return $this->reject('bad-signature');
        }

        $event = json_decode($raw, true);

        if (! is_array($event) || ! isset($event['event'])) {
            return $this->accept('unrecognised-payload');
        }

        $type = (string) $event['event'];
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $reference = isset($data['reference']) ? (string) $data['reference'] : null;

        return match (true) {
            $type === 'charge.success' => $this->credit(
                GatewayConfig::PAYSTACK,
                $this->eventId('paystack', $type, $data),
                $type,
                $reference,
                $this->amountInNaira($data),
                $event
            ),

            // Dedicated virtual account lifecycle - recorded, never a credit.
            in_array($type, [
                'dedicatedaccount.assign.success',
                'dedicatedaccount.assign.failed',
                'customeridentification.success',
                'customeridentification.failed',
            ], true) => $this->recordLifecyle(GatewayConfig::PAYSTACK, $type, $data),

            // Outbound payouts.
            $type === 'transfer.success' => $this->transfer('success', $reference, $event),
            $type === 'transfer.failed' => $this->transfer('failed', $reference, $event),
            $type === 'transfer.reversed' => $this->transfer('failed', $reference, $event),

            default => $this->accept('ignored-event'),
        };
    }

    /* ---------------------------------------------------------------------- */
    /* Flutterwave                                                             */
    /* ---------------------------------------------------------------------- */

    public function flutterwave(Request $request): JsonResponse
    {
        $signature = (string) $request->header('verif-hash', '');

        if (! GatewayConfig::enabled(GatewayConfig::FLUTTERWAVE)) {
            return $this->reject('flutterwave-disabled');
        }

        if (! FlutterwaveGateway::verifySignature($signature)) {
            Log::warning('Flutterwave webhook rejected: signature mismatch.');

            return $this->reject('bad-signature');
        }

        $event = $request->input();

        if (! is_array($event) || ! isset($event['event'])) {
            return $this->accept('unrecognised-payload');
        }

        $type = (string) $event['event'];
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($type === 'charge.completed' && ($data['status'] ?? null) === 'successful') {
            return $this->credit(
                GatewayConfig::FLUTTERWAVE,
                $this->eventId('flutterwave', $type, $data),
                $type,
                isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
                isset($data['amount']) ? (float) $data['amount'] : null,
                $event
            );
        }

        if ($type === 'transfer.completed') {
            return $this->transfer(
                'success',
                isset($data['reference']) ? (string) $data['reference'] : null,
                $event
            );
        }

        if ($type === 'transfer.failed') {
            return $this->transfer(
                'failed',
                isset($data['reference']) ? (string) $data['reference'] : null,
                $event
            );
        }

        return $this->accept('ignored-event');
    }

    /* ---------------------------------------------------------------------- */
    /* Handlers                                                                */
    /* ---------------------------------------------------------------------- */

    private function credit(
        string $provider,
        string $eventId,
        string $type,
        ?string $reference,
        ?float $amount,
        array $payload,
    ): JsonResponse {
        $result = $this->engine->handleCredit($provider, $eventId, $type, $reference, $amount, $payload);

        // A failure the provider can fix by retrying must NOT be a 200 -
        // otherwise their retry schedule never runs and the money never lands.
        if ($result['status'] === PaymentEvent::STATUS_FAILED) {
            Log::warning('Webhook credit could not be settled.', [
                'provider' => $provider,
                'event_id' => $eventId,
                'reason' => $result['message'],
            ]);

            return response()->json([
                'received' => false,
                'reason' => $result['message'],
            ], 500);
        }

        return $this->accept($result['status']);
    }

    private function transfer(?string $outcome, ?string $reference, array $payload): JsonResponse
    {
        if ($outcome === null || $reference === null) {
            return $this->accept('missing-reference');
        }

        $eventId = $this->eventId('transfer', $outcome, ['reference' => $reference]);
        $result = $this->engine->handleTransferResult($eventId, $reference, $outcome, $payload);

        return $result['status'] === PaymentEvent::STATUS_FAILED
            ? response()->json(['received' => false, 'reason' => $result['message']], 500)
            : $this->accept($result['status']);
    }

    private function recordLifecyle(string $provider, string $type, array $data): JsonResponse
    {
        $eventId = $this->eventId($provider, $type, $data);

        $event = PaymentEvent::claim($eventId, $type, $provider, $data['account_number'] ?? null);

        if ($event === null) {
            return $this->accept('duplicate');
        }

        $failed = str_ends_with($type, '.failed');

        $event->forceFill([
            'status' => $failed ? PaymentEvent::STATUS_FAILED : PaymentEvent::STATUS_PROCESSED,
            'error' => $failed ? (string) ($data['reason'] ?? $type) : null,
            'processed_at' => now(),
        ])->save();

        return $this->accept($event->status);
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * A stable identity for the event across retries. Paystack does not send
     * a delivery id, so the tuple (provider, type, resource id) is what makes
     * a re-sent notification recognisable as the same news.
     */
    private function eventId(string $provider, string $type, array $data): string
    {
        $identity = $data['id']
            ?? $data['reference']
            ?? $data['transfer_code']
            ?? $data['tx_ref']
            ?? $data['account_number']
            ?? json_encode($data);

        return substr(sha1($provider.'|'.$type.'|'.json_encode($identity)), 0, 64);
    }

    /** Paystack reports kobo; Flutterwave reports naira. */
    private function amountInNaira(array $data): ?float
    {
        if (! isset($data['amount'])) {
            return null;
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));

        return round(((float) $data['amount']) / ($currency === 'NGN' ? PaystackGateway::AMOUNT_SCALE : 1), 2);
    }

    private function accept(string $reason): JsonResponse
    {
        return response()->json(['received' => true, 'reason' => $reason]);
    }

    private function reject(string $reason): JsonResponse
    {
        return response()->json(['received' => false, 'reason' => $reason], 401);
    }
}
