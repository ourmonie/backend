<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\RewardAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Creates the permanent account structure every verified member receives:
 *
 *   wallet  + swift_save + quest_save + time_vault
 *   wallet record with a dedicated account number
 *   reward account
 */
class AccountProvisioner
{
    public function provisionFor(Member $member): Member
    {
        return DB::transaction(function () use ($member) {
            foreach ([
                MemberAccount::TYPE_WALLET,
                MemberAccount::TYPE_SWIFT,
                MemberAccount::TYPE_QUEST,
                MemberAccount::TYPE_VAULT,
            ] as $type) {
                $this->accountFor($member, $type);
            }

            if ($member->wallet()->doesntExist()) {
                $walletAccount = $this->accountFor($member, MemberAccount::TYPE_WALLET);

                Wallet::create([
                    'member_id' => $member->id,
                    'member_account_id' => $walletAccount->id,
                    'account_number' => $this->uniqueAccountNumber(),
                    'account_name' => $member->displayName(),
                    'bank_name' => 'OurMonie',
                    'provider' => 'paystack',
                    'status' => 'active',
                    'assigned_at' => now(),
                ]);
            }

            RewardAccount::firstOrCreate(
                ['member_id' => $member->id],
                ['points_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0]
            );

            return $member->refresh()->load([
                'accounts', 'wallet.account', 'rewardAccount', 'tier',
            ]);
        });
    }

    /**
     * Fetch (or lazily create) one of a member's product accounts.
     */
    public function accountFor(Member $member, string $type): MemberAccount
    {
        $existing = MemberAccount::where('member_id', $member->id)
            ->where('type', $type)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $ledgerCode = $type === MemberAccount::TYPE_WALLET
            ? LedgerAccount::MEMBER_WALLET
            : LedgerAccount::MEMBER_SAVINGS;

        return MemberAccount::create([
            'member_id' => $member->id,
            'type' => $type,
            'account_number' => $this->uniqueMemberAccountNumber(),
            'balance' => 0,
            'currency' => 'NGN',
            'status' => 'active',
            'ledger_account_id' => LedgerAccount::byCode($ledgerCode)->id,
        ]);
    }

    /**
     * 10-digit dedicated virtual account number.
     */
    public function uniqueAccountNumber(): string
    {
        return $this->uniqueNumber(
            fn (string $candidate) => Wallet::where('account_number', $candidate)->exists()
                || MemberAccount::where('account_number', $candidate)->exists()
        );
    }

    private function uniqueMemberAccountNumber(): string
    {
        return $this->uniqueNumber(
            fn (string $candidate) => MemberAccount::where('account_number', $candidate)->exists()
        );
    }

    private function uniqueNumber(callable $exists): string
    {
        do {
            // Nigerian DVA numbers are 10 digits; keep a stable-looking BIN.
            $candidate = '0'.random_int(100000000, 999999999);
        } while ($exists($candidate));

        return $candidate;
    }

    /**
     * Sequential public member number, e.g. OM0002458.
     */
    public static function nextMemberNumber(int $memberId): string
    {
        return 'OM'.str_pad((string) $memberId, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Human-friendly referral code, e.g. ADE24XY9.
     */
    public static function generateReferralCode(string $name): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
        $prefix = substr(str_pad($prefix, 3, 'X'), 0, 3);

        do {
            $suffix = '';
            for ($i = 0; $i < 5; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.$suffix;
        } while (Member::where('referral_code', $code)->exists());

        return $code;
    }
}
