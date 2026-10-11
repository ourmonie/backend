<?php

namespace App\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave REST client (https://api.flutterwave.com/v3).
 *
 * The second rail OurMonie can collect and disburse through. It mirrors the
 * Paystack surface one-for-one so the wallet never has to know which provider
 * is behind a given request:
 *
 *   - balance          : prove a key pair works before it is switched on
 *   - virtual account  : issue a member their account number
 *   - transaction verify: settle inbound credits idempotently
 *   - transfer         : pay a member out to their bank account
 *   - bank resolve     : confirm a withdrawal beneficiary first
 */
class FlutterwaveGateway
{
    public const BASE_URL = 'https://api.flutterwave.com/v3';

    private string $secret;

    private string $mode;

    private function __construct(string $secret, string $mode)
    {
        $this->secret = $secret;
        $this->mode = $mode;
    }

    /**
     * @throws GatewayException when the gateway is off or has no key saved
     */
    public static function make(): self
    {
        $secret = GatewayConfig::secretKey(GatewayConfig::FLUTTERWAVE);

        if ($secret === null) {
            throw new GatewayException('Flutterwave has no secret key saved for the selected environment.');
        }

        return new self($secret, GatewayConfig::mode(GatewayConfig::FLUTTERWAVE));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /* ---------------------------------------------------------------------- */
    /* Connectivity                                                            */
    /* ---------------------------------------------------------------------- */

    public function checkConnection(): array
    {
        $data = $this->request('GET', '/balances?type=NGN');

        $balances = collect($data ?? []);
        $ngn = $balances->first(fn (array $balance) => ($balance['currency'] ?? null) === 'NGN')
            ?? $balances->first();

        return [
            'connected' => true,
            'mode' => $this->mode,
            'currency' => $ngn['currency'] ?? 'NGN',
            'balance' => isset($ngn['balance']) ? number_format((float) $ngn['balance'], 2) : null,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Virtual accounts                                                        */
    /* ---------------------------------------------------------------------- */

    /**
     * Issue a permanent virtual account for a member.
     *
     * @return array{account_number:?string,account_name:?string,bank_name:?string,status:string,raw:array}
     */
    public function createVirtualAccount(array $attributes): array
    {
        $data = $this->request('POST', '/virtual-account-nums', [
            'email' => $attributes['email'],
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'phone_number' => $attributes['phone'] ?? null,
            'is_permanent' => true,
            'bvn' => $attributes['bvn'] ?? null,
            'tx_ref' => $attributes['tx_ref'] ?? null,
        ]);

        return [
            'account_number' => isset($data['account_number']) ? (string) $data['account_number'] : null,
            'account_name' => $data['account_name'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'status' => 'assigned',
            'raw' => $data,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Transactions                                                            */
    /* ---------------------------------------------------------------------- */

    /**
     * Authoritative read on a charge before any wallet is credited.
     *
     * @return array{status:string,amount:float,reference:string,currency:string,channel:?string,raw:array}
     */
    public function verifyTransaction(int|string $id): array
    {
        $data = $this->request('GET', '/transactions/'.rawurlencode((string) $id).'/verify');

        return [
            'status' => (string) ($data['status'] ?? ''),
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'reference' => (string) ($data['tx_ref'] ?? $data['id'] ?? ''),
            'currency' => (string) ($data['currency'] ?? 'NGN'),
            'channel' => $data['payment_type'] ?? null,
            'raw' => $data,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Transfers (payouts)                                                     */
    /* ---------------------------------------------------------------------- */

    public function createTransferRecipient(array $attributes): array
    {
        $data = $this->request('POST', '/beneficiaries', [
            'account_bank' => $attributes['bank_code'],
            'account_number' => $attributes['account_number'],
            'beneficiary_name' => $attributes['name'],
        ]);

        return [
            'recipient_code' => isset($data['id']) ? (string) $data['id'] : null,
            'raw' => $data,
        ];
    }

    /**
     * @return array{transfer_code:string,status:string,raw:array}
     */
    public function initiateTransfer(array $attributes): array
    {
        $data = $this->request('POST', '/transfers', [
            'account_bank' => $attributes['bank_code'],
            'account_number' => $attributes['account_number'],
            'amount' => round((float) $attributes['amount'], 2),
            'narration' => $attributes['reason'] ?? 'OurMonie withdrawal',
            'currency' => 'NGN',
            'reference' => $attributes['reference'],
            'debit_currency' => 'NGN',
        ]);

        return [
            'transfer_code' => (string) ($data['transfer_id'] ?? $data['id'] ?? ''),
            'status' => (string) ($data['status'] ?? 'SUCCESSFUL'),
            'raw' => $data,
        ];
    }

    public function fetchTransfer(string $transferId): array
    {
        return $this->request('GET', '/transfers/'.rawurlencode($transferId));
    }

    /* ---------------------------------------------------------------------- */
    /* Directory                                                               */
    /* ---------------------------------------------------------------------- */

    public function banks(): array
    {
        return $this->request('GET', '/banks?country=nigeria')['data'] ?? [];
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $data = $this->request('GET', sprintf(
            '/banks/%s/accounts/%s/resolve',
            rawurlencode($bankCode),
            rawurlencode($accountNumber)
        ));

        return [
            'account_number' => (string) ($data['account_number'] ?? $accountNumber),
            'account_name' => (string) ($data['account_name'] ?? ''),
            'bank_id' => $data['bank_id'] ?? null,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Webhook signature                                                       */
    /* ---------------------------------------------------------------------- */

    /**
     * Flutterwave sends the shared secret as `verif-hash`, compared against
     * the hash saved for the environment that sent the event. Both saved
     * hashes are tried so test and live each verify against their own.
     */
    public static function verifySignature(string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        foreach ([null, 'test', 'live'] as $mode) {
            $expected = GatewayConfig::webhookSecret(GatewayConfig::FLUTTERWAVE, $mode);

            if ($expected === null) {
                continue;
            }

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------- */
    /* Internals                                                               */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array<string, mixed> the `data` envelope, unwrapped
     *
     * @throws GatewayException on transport failure or a rejected call
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $response = $this->send($method, $path, $payload);

        $body = $response->json();

        if (! is_array($body)) {
            throw new GatewayException('Flutterwave returned an unreadable response.', $response->status());
        }

        $status = (string) ($body['status'] ?? '');

        if ($response->failed() || ! in_array($status, ['success', 'OK'], true)) {
            throw new GatewayException(
                (string) ($body['message'] ?? 'Flutterwave rejected the request.'),
                $response->status()
            );
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    private function send(string $method, string $path, array $payload): \Illuminate\Http\Client\Response
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return match (strtoupper($method)) {
                    'GET' => Http::withToken($this->secret)
                        ->acceptJson()
                        ->timeout(20)
                        ->get(self::BASE_URL.$path, $payload),
                    'POST' => Http::withToken($this->secret)
                        ->acceptJson()
                        ->asJson()
                        ->timeout(20)
                        ->post(self::BASE_URL.$path, $payload),
                    default => throw new GatewayException('Unsupported method: '.$method),
                };
            } catch (ConnectionException $exception) {
                if ($attempts >= 2) {
                    throw new GatewayException('Could not reach Flutterwave: '.$exception->getMessage(), 0, $exception);
                }

                usleep(400_000);
            }
        }
    }
}
