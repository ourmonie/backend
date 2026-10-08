<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Chart of accounts.
 *
 * The `group` column is what keeps member-owned money provably separate
 * from cooperative income, fees, rewards and interest at reporting time.
 */
class LedgerAccount extends Model
{
    protected $fillable = [
        'code', 'name', 'type', 'normal_side', 'group',
        'is_system', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* ----------------------------- asset (debit) ---------------------------- */

    public const CASH_AT_BANK = '1001';
    public const PAYSTACK_CLEARING = '1002';
    public const RECEIVABLES = '1003';

    /* -------------------- liabilities (credit) = member funds --------------- */

    public const MEMBER_WALLET = '2001';
    public const MEMBER_SAVINGS = '2002';
    public const REWARD_LIABILITY = '2003';
    public const DUE_TO_PROVIDER = '2004';

    /* ------------------------------- equity --------------------------------- */

    public const COOPERATIVE_EQUITY = '3001';
    public const RETAINED_EARNINGS = '3002';

    /* ----------------------- revenue (credit) = income ---------------------- */

    public const FEE_INCOME = '4001';
    public const MEMBERSHIP_FEE_INCOME = '4002';
    public const COMMISSION_INCOME = '4003';
    public const INTEREST_RECOVERED = '4004';

    /* ----------------------- expense (debit) = costs ------------------------ */

    public const INTEREST_EXPENSE = '5001';
    public const REFERRAL_EXPENSE = '5002';
    public const REWARD_EXPENSE = '5003';
    public const PENALTY_INCOME = '4005';

    /** System chart of accounts, seeded once. */
    public const SYSTEM_CHART = [
        ['code' => self::CASH_AT_BANK, 'name' => 'Cash at Bank', 'type' => 'asset', 'normal_side' => 'debit', 'group' => 'clearing'],
        ['code' => self::PAYSTACK_CLEARING, 'name' => 'Paystack Clearing', 'type' => 'asset', 'normal_side' => 'debit', 'group' => 'clearing'],
        ['code' => self::RECEIVABLES, 'name' => 'Member Receivables', 'type' => 'asset', 'normal_side' => 'debit', 'group' => 'clearing'],
        ['code' => self::MEMBER_WALLET, 'name' => 'Member Wallet Funds', 'type' => 'liability', 'normal_side' => 'credit', 'group' => 'member_funds'],
        ['code' => self::MEMBER_SAVINGS, 'name' => 'Member Savings Funds', 'type' => 'liability', 'normal_side' => 'credit', 'group' => 'member_funds'],
        ['code' => self::REWARD_LIABILITY, 'name' => 'Reward Points Liability', 'type' => 'liability', 'normal_side' => 'credit', 'group' => 'rewards'],
        ['code' => self::DUE_TO_PROVIDER, 'name' => 'Due to Payment Provider', 'type' => 'liability', 'normal_side' => 'credit', 'group' => 'clearing'],
        ['code' => self::COOPERATIVE_EQUITY, 'name' => 'Cooperative Equity', 'type' => 'equity', 'normal_side' => 'credit', 'group' => 'equity'],
        ['code' => self::RETAINED_EARNINGS, 'name' => 'Retained Earnings', 'type' => 'equity', 'normal_side' => 'credit', 'group' => 'equity'],
        ['code' => self::FEE_INCOME, 'name' => 'Transaction & Withdrawal Fees', 'type' => 'revenue', 'normal_side' => 'credit', 'group' => 'fees'],
        ['code' => self::MEMBERSHIP_FEE_INCOME, 'name' => 'Membership Fees', 'type' => 'revenue', 'normal_side' => 'credit', 'group' => 'fees'],
        ['code' => self::COMMISSION_INCOME, 'name' => 'Service Commissions', 'type' => 'revenue', 'normal_side' => 'credit', 'group' => 'cooperative_income'],
        ['code' => self::INTEREST_RECOVERED, 'name' => 'Interest Recovered', 'type' => 'revenue', 'normal_side' => 'credit', 'group' => 'cooperative_income'],
        ['code' => self::PENALTY_INCOME, 'name' => 'Early Withdrawal Penalties', 'type' => 'revenue', 'normal_side' => 'credit', 'group' => 'cooperative_income'],
        ['code' => self::INTEREST_EXPENSE, 'name' => 'Interest Paid to Members', 'type' => 'expense', 'normal_side' => 'debit', 'group' => 'interest'],
        ['code' => self::REFERRAL_EXPENSE, 'name' => 'Referral Rewards', 'type' => 'expense', 'normal_side' => 'debit', 'group' => 'rewards'],
        ['code' => self::REWARD_EXPENSE, 'name' => 'Reward Point Costs', 'type' => 'expense', 'normal_side' => 'debit', 'group' => 'rewards'],
    ];

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    /**
     * Signed balance of a ledger account using its normal side.
     */
    public function balance(): float
    {
        $debits = (float) LedgerEntry::where('ledger_account_id', $this->id)
            ->where('direction', 'debit')
            ->sum('amount');

        $credits = (float) LedgerEntry::where('ledger_account_id', $this->id)
            ->where('direction', 'credit')
            ->sum('amount');

        return $this->normal_side === 'debit'
            ? round($debits - $credits, 2)
            : round($credits - $debits, 2);
    }
}
