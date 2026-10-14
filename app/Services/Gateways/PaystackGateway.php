<?php

namespace App\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Paystack REST client (https://api.paystack.co).
 *
 * Covers exactly what OurMonie needs and nothing else:
 *
 *   - balance / verify  : prove a key pair works before it is switched on
 *   - customer + dedicated_account : issue a member their NUBAN
 *   - transaction verify + requery  : settle inbound transfers idempotently
 *   - transfer         : pay a member out to their bank account
 *   - bank resolve     : confirm a withdrawal beneficiary before money moves
 *
 * Every call carries the secret key for the environment currently selected in
 * GatewayConfig, so test keys can never sign a live request.
 */
class PaystackGateway
{
    public const BASE_URL = 'https://api.paystack.co';

    /** Paystack amounts are in kobo. */
    public const AMOUNT_SCALE = 100;

    private string $secret;

    private string $mode;

    private function __construct(string $secret, string $mode)
    {
        $this->secret = $secret;
        $this->mode = $mode;
    }

    /**
     * Build a client for the configured environment.
     *
     * @throws GatewayException when the gateway is off or has no key saved
     */
    public static function make(): self
    {
        $secret = GatewayConfig::secretKey(GatewayConfig::PAYSTACK);

        if ($secret === null) {
            throw new GatewayException('Paystack has no secret key saved for the selected environment.');
        }

        return new self($secret, GatewayConfig::mode(GatewayConfig::PAYSTACK));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /* ---------------------------------------------------------------------- */
    /* Connectivity                                                            */
    /* ---------------------------------------------------------------------- */

    /** Proves the saved key authenticates. Returns the masked balance. */
    public function checkConnection(): array
    {
        $data = $this->request('GET', '/balance');

        $balances = collect($data['balance'] ?? []);

        $ngn = $balances->first(fn (array $balance) => ($balance['currency'] ?? null) === 'NGN')
            ?? $balances->first();

        return [
            'connected' => true,
            'mode' => $this->mode,
            'currency' => $ngn['currency'] ?? 'NGN',
            'balance' => isset($ngn['balance'])
                ? number_format(((float) $ngn['balance']) / self::AMOUNT_SCALE, 2)
                : null,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Customers & dedicated virtual accounts                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * Create (or find) the Paystack customer behind a member.
     *
     * @return array{customer_code:string,id:int|string}
     */
    public function createCustomer(array $attributes): array
    {
        $data = $this->request('POST', '/customer', [
            'email' => $attributes['email'],
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'phone' => $attributes['phone'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        return [
            'id' => $data['id'] ?? null,
            'customer_code' => $data['customer_code'] ?? null,
        ];
    }

    /**
     * Assign a dedicated virtual account (NUBAN) to a customer.
     *
     * Test keys only accept `test-bank`; live keys take a real provider slug.
     * The response may be immediate (account number returned) or deferred
     * (provider still assigning) - both shapes are normalised here.
     *
     * @return array{account_number:?string,account_name:?string,bank_name:?string,status:string,raw:array}
     */
    public function createDedicatedAccount(array $attributes): array
    {
        $payload = [
            'customer' => $attributes['customer'],
            'preferred_bank' => $this->preferredBank($attributes['preferred_bank'] ?? null),
        ];

        if (! empty($attributes['bank_code']) && ! empty($attributes['account_number'])) {
            // Single-step assignment with identity validation - required for
            // financial-services categories in Nigeria.
            $payload += [
                'email' => $attributes['email'],
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'phone' => $attributes['phone'] ?? null,
                'country' => 'NG',
                'account_number' => $attributes['account_number'],
                'bvn' => $attributes['bvn'],
                'bank_code' => $attributes['bank_code'],
            ];
        }

        $data = $this->request('POST', '/dedicated_account', $payload);

        return $this->normaliseDva($data);
    }

    /** Read back an existing NUBAN for a customer. */
    public function fetchCustomer(string $customerCode): array
    {
        return $this->request('GET', '/customer/'.$customerCode);
    }

    /**
     * Ask Paystack to look for transfers that have not reached us yet.
     * Rate limited to one call per account per ten minutes on their side.
     */
    public function requeryDedicatedAccount(string $accountNumber): array
    {
        return $this->request('GET', '/dedicated_account/requery', [
            'account_number' => $accountNumber,
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Transactions                                                            */
    /* ---------------------------------------------------------------------- */

    /**
     * The authoritative read on a charge - webhook payloads are advisory,
     * this is what actually decides whether money moved.
     *
     * @return array{status:string,amount:float,reference:string,currency:string,channel:?string,raw:array}
     */
    public function verifyTransaction(string $reference): array
    {
        $data = $this->request('GET', '/transaction/verify/'.rawurlencode($reference));

        return [
            'status' => (string) ($data['status'] ?? ''),
            'amount' => round(((float) ($data['amount'] ?? 0)) / self::AMOUNT_SCALE, 2),
            'reference' => (string) ($data['reference'] ?? $reference),
            'currency' => (string) ($data['currency'] ?? 'NGN'),
            'channel' => $data['channel'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Recent transactions, newest first.
     *
     * Used for reconciliation rather than for crediting: a list is only
     * ever a way of discovering that a reference exists. Every amount is
     * still re-read through verifyTransaction before it becomes money.
     *
     * @return array<int, array>
     */
    public function listTransactions(array $parameters = []): array
    {
        // `request()` already unwraps the `data` envelope, and for a
        // directory endpoint that envelope *is* the list. Reaching into it
        // again for a nested `data` key finds nothing there, which is how
        // this quietly reported zero transactions on a live account that
        // had just been paid.
        return $this->request('GET', '/transaction', array_merge([
            'perPage' => 100,
            'status' => 'success',
        ], $parameters));
    }

    /* ---------------------------------------------------------------------- */
    /* Transfers (payouts)                                                     */
    /* ---------------------------------------------------------------------- */

    /** Register the member's bank account as a transfer recipient. */
    public function createTransferRecipient(array $attributes): array
    {
        $data = $this->request('POST', '/transferrecipient', [
            'type' => 'nuban',
            'name' => $attributes['name'],
            'account_number' => $attributes['account_number'],
            'bank_code' => $attributes['bank_code'],
            'currency' => 'NGN',
        ]);

        return [
            'recipient_code' => $data['recipient_code'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Push money to a recipient. Returns the transfer identifier the
     * transfer.success / transfer.failed webhook will refer to.
     *
     * @return array{transfer_code:string,status:string,raw:array}
     */
    public function initiateTransfer(array $attributes): array
    {
        $data = $this->request('POST', '/transfer', [
            'source' => 'balance',
            'recipient' => $attributes['recipient_code'],
            'amount' => (int) round(((float) $attributes['amount']) * self::AMOUNT_SCALE),
            'reason' => $attributes['reason'] ?? 'OurMonie withdrawal',
            'reference' => $attributes['reference'],
            'currency' => 'NGN',
        ]);

        return [
            'transfer_code' => $data['transfer_code'] ?? null,
            'status' => (string) ($data['status'] ?? ''),
            'raw' => $data,
        ];
    }

    public function fetchTransfer(string $reference): array
    {
        return $this->request('GET', '/transfer/retrieve/'.rawurlencode($reference));
    }

    /* ---------------------------------------------------------------------- */
    /* Directory                                                               */
    /* ---------------------------------------------------------------------- */

    public function banks(): array
    {
        return $this->request('GET', '/bank', ['paginate' => 200])['data'] ?? [];
    }

    /** Confirm a beneficiary account before any money leaves. */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $data = $this->request('POST', '/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

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
     * x-paystack-signature is an HMAC-SHA512 of the raw body signed with the
     * secret key (or the dedicated webhook secret) of the environment that
     * sent it. Compared in constant time against both saved secrets so a
     * test-mode event and a live-mode event each verify against their own.
     */
    public static function verifySignature(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        foreach ([null, 'test', 'live'] as $mode) {
            $secret = GatewayConfig::webhookSecret(GatewayConfig::PAYSTACK, $mode);

            if ($secret === null) {
                continue;
            }

            $expected = hash_hmac('sha512', $rawBody, $secret);

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
     * Test keys only recognise `test-bank`; live keys take a configured slug.
     */
    private function preferredBank(?string $configured): string
    {
        if ($this->mode === 'test') {
            return 'test-bank';
        }

        return $configured !== null && $configured !== '' ? $configured : 'wema-bank';
    }

    private function normaliseDva(array $data): array
    {
        return [
            'account_number' => isset($data['account_number']) ? (string) $data['account_number'] : null,
            'account_name' => $data['account_name'] ?? null,
            'bank_name' => $data['bank']['name'] ?? null,
            'bank_slug' => $data['bank']['slug'] ?? null,
            'status' => ! empty($data['assigned']) ? 'assigned' : 'pending',
            'id' => $data['id'] ?? null,
            'raw' => $data,
        ];
    }

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
            throw new GatewayException('Paystack returned an unreadable response.', $response->status());
        }

        if ($response->failed()) {
            throw new GatewayException(
                (string) ($body['message'] ?? 'Paystack rejected the request.'),
                $response->status()
            );
        }

        if (($body['status'] ?? false) !== true) {
            throw new GatewayException((string) ($body['message'] ?? 'Paystack reported a failure.'));
        }

        // `data` is an object for single resources and a list for directories;
        // anything else (null, scalar) is treated as an empty result.
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * One connection failure is retried - gateways are flaky far more often
     * than they are down. A rejected response is never retried: Paystack has
     * already made up its mind and repeating the call changes nothing.
     */
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
                    throw new GatewayException('Could not reach Paystack: '.$exception->getMessage(), 0, $exception);
                }

                usleep(400_000);
            }
        }
    }
}
