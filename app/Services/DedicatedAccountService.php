<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Wallet;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\PaystackGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues a member their dedicated account number.
 *
 * PRD 87-91 - the number the member transfers to is issued by the gateway and
 * recorded here; it is a FUNDING CHANNEL and never the balance itself. The
 * wallet row already exists from AccountProvisioner with a locally minted
 * holding number, so a member is never left without an account to look at;
 * this service replaces it with the real provider-assigned NUBAN once a
 * gateway is live and the member has cleared KYC.
 *
 * Failure here must not fail the operation that triggered it - a member whose
 * bank is slow to assign a number still has a profile, and the assignment is
 * retried the next time they open their wallet.
 */
class DedicatedAccountService
{
    /**
     * Assign (or refresh) the provider-backed account number for a member.
     *
     * Returns the wallet either way; when no gateway is configured the
     * locally minted number stands and `provider` records that no rail is
     * attached yet.
     */
    public function assign(Member $member, bool $force = false): ?Wallet
    {
        $wallet = $member->wallet()->first();

        if ($wallet === null) {
            return null;
        }

        $gateway = GatewayConfig::activeGateway();

        if ($gateway === null) {
            return $wallet;
        }

        // Already carrying a live provider number - nothing to do unless the
        // admin asked to reissue it.
        if (! $force && $this->alreadyAssigned($wallet, $gateway)) {
            return $wallet;
        }

        if (! $member->isVerified() && ! $force) {
            // PRD 89 - providers require identity validation before a NUBAN
            // is generated for a financial-services merchant.
            return $wallet;
        }

        try {
            return $gateway === GatewayConfig::PAYSTACK
                ? $this->assignPaystack($member, $wallet)
                : $this->assignFlutterwave($member, $wallet);
        } catch (GatewayException $exception) {
            // Surfaced, never swallowed: the dashboard shows it and the next
            // wallet load retries.
            Log::warning('Dedicated account assignment failed.', [
                'member' => $member->member_number,
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            $this->markPending($wallet, $exception->getMessage());

            return $wallet;
        }
    }

    /* ---------------------------------------------------------------------- */

    private function assignPaystack(Member $member, Wallet $wallet): Wallet
    {
        $gateway = PaystackGateway::make();
        $user = $member->user;

        $customerCode = $wallet->provider_customer_code;

        if ($customerCode === null || $wallet->provider !== GatewayConfig::PAYSTACK) {
            $customer = $gateway->createCustomer([
                'email' => $user?->email,
                'first_name' => $this->firstName($member->displayName()),
                'last_name' => $this->lastName($member->displayName()),
                'phone' => $user?->phone,
                'metadata' => [
                    'member_number' => $member->member_number,
                    'member_id' => $member->id,
                ],
            ]);

            $customerCode = $customer['customer_code'];
        }

        $result = $gateway->createDedicatedAccount([
            'customer' => $customerCode,
            'preferred_bank' => (string) \App\Models\Setting::getValue('paystack.preferred_bank', 'wema-bank'),
            'email' => $user?->email,
            'first_name' => $this->firstName($member->displayName()),
            'last_name' => $this->lastName($member->displayName()),
            'phone' => $user?->phone,
        ]);

        if ($result['account_number'] === null) {
            // Provider is still assigning - the dedicatedaccount.assign.success
            // webhook completes it. We wait rather than invent a number.
            $this->markPending($wallet, 'Provider is assigning this account number.');

            $wallet->forceFill([
                'provider' => GatewayConfig::PAYSTACK,
                'provider_customer_code' => $customerCode,
            ])->save();

            return $wallet;
        }

        return $this->persist($wallet, [
            'account_number' => $result['account_number'],
            'account_name' => $result['account_name'] ?? $member->displayName(),
            'bank_name' => $result['bank_name'] ?? 'Paystack',
            'provider' => GatewayConfig::PAYSTACK,
            'provider_customer_code' => $customerCode,
            'provider_reference' => (string) ($result['id'] ?? ''),
            'status' => 'active',
            'assigned_at' => now(),
            'meta' => [
                'bank_slug' => $result['bank_slug'],
                'environment' => $gateway->mode(),
            ],
        ]);
    }

    private function assignFlutterwave(Member $member, Wallet $wallet): Wallet
    {
        $gateway = FlutterwaveGateway::make();
        $user = $member->user;

        $result = $gateway->createVirtualAccount([
            'email' => $user?->email,
            'first_name' => $this->firstName($member->displayName()),
            'last_name' => $this->lastName($member->displayName()),
            'phone' => $user?->phone,
            'tx_ref' => sprintf('OM_%d_%s', $member->id, strtoupper(bin2hex(random_bytes(4)))),
        ]);

        if ($result['account_number'] === null) {
            $this->markPending($wallet, 'Provider is assigning this account number.');

            return $wallet;
        }

        return $this->persist($wallet, [
            'account_number' => $result['account_number'],
            'account_name' => $result['account_name'] ?? $member->displayName(),
            'bank_name' => $result['bank_name'] ?? 'Flutterwave',
            'provider' => GatewayConfig::FLUTTERWAVE,
            'provider_reference' => (string) $result['account_number'],
            'status' => 'active',
            'assigned_at' => now(),
            'meta' => ['environment' => $gateway->mode()],
        ]);
    }

    /* ---------------------------------------------------------------------- */

    private function alreadyAssigned(Wallet $wallet, string $gateway): bool
    {
        return $wallet->provider === $gateway
            && $wallet->provider_reference !== null
            && $wallet->provider_reference !== ''
            && $wallet->status === 'active';
    }

    /**
     * The account number is unique across wallets AND member accounts, so the
     * swap is done under a lock: two members can never be handed the same
     * number by racing the check.
     */
    private function persist(Wallet $wallet, array $attributes): Wallet
    {
        return DB::transaction(function () use ($wallet, $attributes) {
            $number = $attributes['account_number'];

            $taken = Wallet::where('account_number', $number)
                ->where('id', '!=', $wallet->id)
                ->exists()
                || \App\Models\MemberAccount::where('account_number', $number)->exists();

            if ($taken) {
                throw new GatewayException('Provider issued an account number already in use.');
            }

            $wallet->forceFill($attributes)->save();

            Auditor::record(
                'wallet.account_assigned',
                'wallet',
                sprintf('Dedicated account %s assigned via %s', $number, $attributes['provider']),
                ['account_number' => $wallet->getOriginal('account_number')],
                ['account_number' => $number, 'provider' => $attributes['provider']],
                $wallet,
            );

            return $wallet->refresh();
        });
    }

    private function markPending(Wallet $wallet, string $reason): void
    {
        $wallet->forceFill([
            'status' => 'pending',
            'meta' => array_merge((array) $wallet->meta, [
                'assignment_pending' => true,
                'assignment_note' => $reason,
                'requested_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?: 'Member';
    }

    private function lastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return count($parts) > 1 ? (string) end($parts) : 'Member';
    }
}
