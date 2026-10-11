<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PaymentEvent;
use App\Services\Auditor;
use App\Services\DedicatedAccountService;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\PaystackGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Payments screen's API surface (PRD 21).
 *
 * Reads only readiness and masked hints - no endpoint here returns a secret,
 * and none accepts one either: keys are saved through the ordinary settings
 * group so they pass the same validation and the same masking on the way out.
 *
 * The two actions are:
 *   - test a gateway: call the provider with the saved key and report back
 *   - assign account: issue a member their dedicated number on demand
 */
class AdminPaymentGatewayController extends Controller
{
    public function __construct(private readonly DedicatedAccountService $accounts)
    {
    }

    /**
     * Everything the Payments screen needs in one round trip: which gateways
     * exist, which is live, which environment each is pointed at, and where
     * the webhook must be registered.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'gateways' => GatewayConfig::status(),
            'active_gateway' => GatewayConfig::activeGateway(),
            'webhooks' => [
                'paystack' => [
                    'url' => route('webhooks.paystack'),
                    'event' => 'charge.success',
                ],
                'flutterwave' => [
                    'url' => route('webhooks.flutterwave'),
                    'event' => 'charge.completed',
                ],
            ],
            'events' => $this->recentEvents(),
        ]);
    }

    /**
     * Prove a saved key pair authenticates before the admin flips it live.
     * Runs against whichever environment is currently selected.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:paystack,flutterwave'],
        ]);

        $gateway = $validated['gateway'];

        if (! GatewayConfig::enabled($gateway)) {
            // Testing a disabled gateway is legitimate - it is exactly what
            // you do before enabling it - so this is not an error, just a
            // note that nothing is switched on yet.
        }

        try {
            $result = $gateway === GatewayConfig::PAYSTACK
                ? PaystackGateway::make()->checkConnection()
                : FlutterwaveGateway::make()->checkConnection();
        } catch (GatewayException $exception) {
            Log::warning('Gateway connectivity test failed.', [
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'connected' => false,
                'gateway' => $gateway,
                'mode' => GatewayConfig::mode($gateway),
                'message' => $exception->getMessage(),
            ], 422);
        }

        Auditor::record(
            'gateway.tested',
            'gateway',
            sprintf('%s %s keys verified', $gateway, $result['mode']),
            [],
            ['gateway' => $gateway, 'mode' => $result['mode']],
            null,
        );

        return response()->json(array_merge($result, [
            'gateway' => $gateway,
            'message' => sprintf(
                '%s %s keys authenticate. Balance: ₦%s %s.',
                ucfirst($gateway),
                $result['mode'],
                $result['balance'] ?? '0.00',
                $result['currency']
            ),
        ]));
    }

    /**
     * Issue (or reissue) a member's dedicated account number.
     *
     * Offered per member in the admin portal so an assignment that failed at
     * registration - no gateway configured yet, provider outage - can be
     * retried without the member doing anything.
     */
    public function assignAccount(Request $request, int $memberId): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        $member = Member::with(['user', 'wallet'])->findOrFail($memberId);

        if (GatewayConfig::activeGateway() === null) {
            return response()->json([
                'message' => 'No payment gateway is active with a saved key. Configure one on the Payments screen first.',
            ], 422);
        }

        $wallet = $this->accounts->assign($member, (bool) ($validated['force'] ?? false));

        $pending = ($wallet?->meta['assignment_pending'] ?? false) === true;

        return response()->json([
            'message' => $pending
                ? ($wallet->meta['assignment_note'] ?? 'The provider is still assigning this number.')
                : 'Account number assigned.',
            'account_number' => $wallet?->account_number,
            'account_name' => $wallet?->account_name,
            'bank_name' => $wallet?->bank_name,
            'provider' => $wallet?->provider,
            'status' => $wallet?->status,
            'pending' => $pending,
        ], $pending ? 202 : 200);
    }

    /* ---------------------------------------------------------------------- */

    /** Recent provider traffic, so an admin can see a webhook actually land. */
    private function recentEvents(): array
    {
        return PaymentEvent::latest('id')
            ->take(20)
            ->get()
            ->map(fn (PaymentEvent $event) => [
                'id' => $event->id,
                'type' => $event->event_type,
                'provider' => $event->provider,
                'reference' => $event->reference,
                'status' => $event->status,
                'error' => $event->error,
                'received_at' => optional($event->created_at)->toIso8601String(),
                'processed_at' => optional($event->processed_at)->toIso8601String(),
            ])
            ->all();
    }
}
