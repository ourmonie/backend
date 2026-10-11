<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsVault;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Named financial operations built on top of LedgerService.
 *
 * None of these methods ever writes a balance directly - they only describe
 * which accounts move, and LedgerService does the posting.
 */
class WalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountProvisioner $provisioner,
    ) {
    }

    /* ---------------------------------------------------------------------- */
    /* Funding                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * A bank transfer landed on the member's dedicated virtual account.
     *
     * Dr Paystack Clearing   (asset grows - provider owes us)
     * Cr Member Wallet       (member liability grows)
     */
    public function fundWallet(
        Member $member,
        float $amount,
        ?string $providerReference = null,
        array $meta = [],
    ): Transaction {
        $walletAccount = $this->provisioner->accountFor($member, MemberAccount::TYPE_WALLET);

        $reference = $providerReference
            ? 'FUND_'.$providerReference
            : Transaction::generateReference('FUND');

        return $this->ledger->post([
            'reference' => $reference,
            'type' => Transaction::WALLET_FUNDING,
            'member_id' => $member->id,
            'member_account_id' => $walletAccount->id,
            'amount' => $amount,
            'channel' => 'bank_transfer',
            'source' => 'paystack',
            'description' => 'Wallet funding via bank transfer',
            'provider_reference' => $providerReference,
            'occurred_at' => now(),
            'meta' => $meta,
            'lines' => [
                ['account' => LedgerAccount::PAYSTACK_CLEARING, 'debit' => $amount, 'memo' => 'DVA credit received'],
                ['member_account_id' => $walletAccount->id, 'credit' => $amount, 'memo' => 'Wallet credited'],
            ],
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Settlement                                                              */
    /* ---------------------------------------------------------------------- */

    /**
     * Paystack settles collected funds into the cooperative's own bank
     * account, clearing the provider float.
     *
     * Dr Cash at Bank
     * Cr Paystack Clearing
     */
    public function settleFromProvider(float $amount, ?string $reference = null): Transaction
    {
        return $this->ledger->post([
            'reference' => $reference ? 'STL_'.$reference : null,
            'type' => Transaction::SETTLEMENT,
            'amount' => $amount,
            'channel' => 'bank_transfer',
            'source' => 'paystack',
            'description' => 'Paystack settlement to cooperative bank account',
            'lines' => [
                ['account' => LedgerAccount::CASH_AT_BANK, 'debit' => $amount, 'memo' => 'Settlement received'],
                ['account' => LedgerAccount::PAYSTACK_CLEARING, 'credit' => $amount, 'memo' => 'Provider float cleared'],
            ],
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Internal allocation                                                     */
    /* ---------------------------------------------------------------------- */

    /**
     * Member moves their own money between eligible products.
     *
     * Dr source member account
     * Cr destination member account
     *
     * This NEVER overwrites a balance - it produces a full audit pair.
     */
    public function allocate(
        Member $member,
        string $fromType,
        string $toType,
        float $amount,
        ?SavingsGoal $goal = null,
    ): Transaction {
        if ($fromType === $toType && $goal === null) {
            throw new InvalidArgumentException('Source and destination must differ.');
        }

        $from = $this->provisioner->accountFor($member, $fromType);
        $to = $this->provisioner->accountFor($member, $toType);

        $transaction = $this->ledger->post([
            'type' => Transaction::INTERNAL_TRANSFER,
            'member_id' => $member->id,
            'member_account_id' => $from->id,
            'amount' => $amount,
            'channel' => 'internal',
            'source' => 'internal',
            'description' => sprintf('%s to %s', $from->label(), $to->label()),
            'lines' => [
                ['member_account_id' => $from->id, 'debit' => $amount, 'memo' => $from->label().' out'],
                ['member_account_id' => $to->id, 'credit' => $amount, 'memo' => $to->label().' in'],
            ],
        ]);

        if ($goal !== null) {
            $this->creditGoal($goal, $amount);
        }

        if ($toType === MemberAccount::TYPE_VAULT) {
            $this->allocateToOldestLockedVault($member, $amount);
        }

        return $transaction;
    }

    /* ---------------------------------------------------------------------- */
    /* Withdrawals                                                             */
    /* ---------------------------------------------------------------------- */

    /**
     * How much of a product account can actually leave right now.
     *
     * A TimeVault balance is not the member's to spend. The account balance
     * includes principal that is contractually locked until maturity, so the
     * headline figure is not the spendable one - and treating it as such
     * would let a member withdraw money they agreed to set aside.
     */
    public function availableBalance(Member $member, string $type): float
    {
        $account = $this->provisioner->accountFor($member, $type);
        $balance = round((float) $account->balance, 2);

        if ($type !== MemberAccount::TYPE_VAULT || $balance <= 0) {
            return $balance;
        }

        // Principal sitting in a pot that has not yet matured. A matured pot
        // is the member's again whether or not they have collected it.
        $locked = round((float) SavingsVault::where('member_account_id', $account->id)
            ->whereNull('withdrawn_at')
            ->where(function ($query) {
                $query->whereNull('matures_on')
                    ->orWhere('matures_on', '>', now()->toDateString());
            })
            ->sum('principal'), 2);

        return round(max(0, $balance - $locked), 2);
    }

    /**
     * Pay out to a Nigerian bank account.
     *
     * Dr source member account (principal + fee)
     * Cr Cash at Bank          (principal)
     * Cr Fee Income            (fee)
     *
     * The money is taken from the member the moment the request is accepted,
     * not when the provider confirms it. That is deliberate: a withdrawal
     * still sitting in `processing` is a live claim on a balance, and if the
     * balance were left intact the same money could be spent a second time
     * before the first transfer settled. Anything that does not settle is
     * handed back by `reversePayout`.
     */
    public function withdraw(
        Member $member,
        string $fromType,
        float $amount,
        float $fee = 0,
        ?string $beneficiary = null,
        ?string $providerReference = null,
        string $description = 'Withdrawal to bank account',
        string $status = Transaction::STATUS_COMPLETED,
        ?string $reference = null,
    ): Transaction {
        $from = $this->provisioner->accountFor($member, $fromType);

        return $this->postPayout(
            $member,
            $from,
            round($amount + $fee, 2),
            $amount,
            $fee,
            $beneficiary,
            $providerReference,
            $description,
            'internal',
            $status,
            $reference,
        );
    }

    /**
     * Hand a payout back to the member because it never reached their bank.
     *
     * This is the mirror image of `withdraw` and posts through the same
     * double-entry path, so a returned withdrawal is visible in the books as
     * a real movement rather than a balance quietly edited back up.
     *
     * Idempotent by construction. The transaction row is locked and only a
     * payout still in `processing` is reversed, so a provider that retries
     * its failure notification - or two webhooks arriving together - cannot
     * credit the member twice.
     */
    public function reversePayout(Transaction $transaction, string $reason): ?Transaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== Transaction::STATUS_PROCESSING) {
                return null;
            }

            $account = $locked->account;

            if ($account === null) {
                return null;
            }

            $amount = round((float) $locked->amount, 2);
            $fee = round((float) $locked->fee_amount, 2);

            $reversal = $this->ledger->post([
                'type' => Transaction::REVERSAL,
                'member_id' => $locked->member_id,
                'member_account_id' => $account->id,
                'amount' => $amount,
                'fee_amount' => 0,
                'channel' => 'bank_transfer',
                'source' => 'system',
                'description' => $reason,
                'counterparty' => $locked->counterparty,
                'occurred_at' => now(),
                'lines' => [
                    ['account' => LedgerAccount::CASH_AT_BANK, 'debit' => $amount, 'memo' => 'Returned payout'],
                    ['member_account_id' => $account->id, 'credit' => round($amount + $fee, 2), 'memo' => 'Withdrawal returned'],
                ],
            ]);

            $locked->forceFill([
                'status' => Transaction::STATUS_REVERSED,
                'meta' => array_merge((array) $locked->meta, [
                    'reversed_at' => now()->toIso8601String(),
                    'reversal_reason' => $reason,
                    'reversal_reference' => $reversal->reference,
                ]),
            ])->save();

            return $reversal;
        });
    }

    /**
     * Empty every product account a member holds, in one direction.
     *
     * Used when a member is being closed out and their position has to be
     * paid to a real bank account. Each account is settled through the
     * same posting every ordinary withdrawal goes through, so a swept
     * member leaves an identical audit trail - there is no privileged path
     * that could quietly remove money from the books.
     *
     * Accounts are taken most liquid first, so a shortfall in a locked
     * TimeVault can never strand a wallet balance already paid out.
     *
     * @param  array<string, string>  $destination  account_number, bank_name, account_name
     * @return array{transactions: array<int, MemberAccount>, total: float}
     */
    public function sweepMemberBalance(Member $member, array $destination): array
    {
        $accounts = $member->accounts()
            ->where('balance', '>', 0)
            ->orderByRaw(
                'FIELD(type, ?, ?, ?, ?)',
                [MemberAccount::TYPE_WALLET, MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT]
            )
            ->get();

        $beneficiary = trim($destination['account_name'].' - '.$destination['bank_name']);
        $statements = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            $amount = round((float) $account->balance, 2);

            if ($amount <= 0) {
                continue;
            }

            $this->postPayout(
                $member,
                $account,
                $amount,
                $amount,
                0,
                $beneficiary,
                null,
                'Balance settled on member closure',
                'admin',
            );

            $statements[] = $account->refresh();
            $total = round($total + $amount, 2);
        }

        return ['transactions' => $statements, 'total' => $total];
    }

    /**
     * One payout posting, shared by member withdrawals and admin sweeps.
     *
     * Dr source account (principal + fee)
     * Cr Cash at Bank            (principal)
     * Cr Fee Income              (fee, when charged)
     */
    private function postPayout(
        Member $member,
        MemberAccount $from,
        float $totalDebit,
        float $amount,
        float $fee,
        ?string $beneficiary,
        ?string $providerReference,
        string $description,
        string $source,
        string $status = Transaction::STATUS_COMPLETED,
        ?string $reference = null,
    ): Transaction {
        $lines = [
            ['member_account_id' => $from->id, 'debit' => $totalDebit, 'memo' => 'Withdrawal'],
            ['account' => LedgerAccount::CASH_AT_BANK, 'credit' => $amount, 'memo' => 'Payout to bank'],
        ];

        if ($fee > 0) {
            $lines[] = ['account' => LedgerAccount::FEE_INCOME, 'credit' => $fee, 'memo' => 'Withdrawal fee'];
        }

        return $this->ledger->post([
            'reference' => $reference,
            'type' => $from->type === MemberAccount::TYPE_WALLET
                ? Transaction::WALLET_WITHDRAWAL
                : Transaction::SAVINGS_WITHDRAWAL,
            'member_id' => $member->id,
            'member_account_id' => $from->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'status' => $status,
            'channel' => 'bank_transfer',
            'source' => $source,
            'description' => $description,
            'counterparty' => $beneficiary,
            'provider_reference' => $providerReference,
            'lines' => $lines,
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Interest & fees                                                         */
    /* ---------------------------------------------------------------------- */

    /**
     * Interest credited to a savings account.
     *
     * Dr Interest Expense
     * Cr member savings account
     */
    public function creditInterest(Member $member, string $accountType, float $amount, ?string $memo = null): Transaction
    {
        $account = $this->provisioner->accountFor($member, $accountType);

        return $this->ledger->post([
            'type' => Transaction::INTEREST,
            'member_id' => $member->id,
            'member_account_id' => $account->id,
            'amount' => $amount,
            'description' => $memo ?? 'Interest earned',
            'lines' => [
                ['account' => LedgerAccount::INTEREST_EXPENSE, 'debit' => $amount, 'memo' => 'Interest accrual'],
                ['member_account_id' => $account->id, 'credit' => $amount, 'memo' => 'Interest credited'],
            ],
        ]);
    }

    /**
     * Standalone fee charge (e.g. membership or service fee).
     *
     * Dr member wallet
     * Cr fee income
     */
    public function chargeFee(Member $member, float $amount, string $description, string $incomeAccount = LedgerAccount::FEE_INCOME): Transaction
    {
        $wallet = $this->provisioner->accountFor($member, MemberAccount::TYPE_WALLET);

        return $this->ledger->post([
            'type' => Transaction::FEE,
            'member_id' => $member->id,
            'member_account_id' => $wallet->id,
            'amount' => $amount,
            'description' => $description,
            'lines' => [
                ['member_account_id' => $wallet->id, 'debit' => $amount, 'memo' => $description],
                ['account' => $incomeAccount, 'credit' => $amount, 'memo' => $description],
            ],
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Reward conversion                                                       */
    /* ---------------------------------------------------------------------- */

    /**
     * Convert reward points into wallet cash.
     *
     * Dr Reward Liability
     * Cr Member Wallet
     */
    public function convertRewards(Member $member, float $cashAmount, int $points, float $rate): Transaction
    {
        $wallet = $this->provisioner->accountFor($member, MemberAccount::TYPE_WALLET);

        return $this->ledger->post([
            'type' => Transaction::REWARD_CONVERSION,
            'member_id' => $member->id,
            'member_account_id' => $wallet->id,
            'amount' => $cashAmount,
            'description' => sprintf('%s points converted to cash', number_format($points)),
            'lines' => [
                ['account' => LedgerAccount::REWARD_LIABILITY, 'debit' => $cashAmount, 'memo' => 'Points redeemed'],
                ['member_account_id' => $wallet->id, 'credit' => $cashAmount, 'memo' => 'Reward conversion'],
            ],
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                 */
    /* ---------------------------------------------------------------------- */

    private function creditGoal(SavingsGoal $goal, float $amount): void
    {
        $goal->current_amount = round((float) $goal->current_amount + $amount, 2);
        $goal->status = $goal->current_amount >= $goal->target_amount ? 'completed' : $goal->status;
        $goal->completed_at = $goal->status === 'completed' ? now() : $goal->completed_at;
        $goal->save();
    }

    /**
     * Fresh funds pushed into TimeVault land in the shared vault account;
     * attribute them to the earliest still-locked vault so per-vault
     * principal totals stay reconcilable.
     */
    private function allocateToOldestLockedVault(Member $member, float $amount): void
    {
        $vault = SavingsVault::where('member_id', $member->id)
            ->where('status', 'locked')
            ->orderBy('matures_on')
            ->first();

        if ($vault === null) {
            return;
        }

        $vault->principal = round((float) $vault->principal + $amount, 2);

        $product = $vault->product;
        $interest = $product !== null
            ? $product->projectInterest((float) $vault->principal, $vault->term_days)
            : 0;

        $vault->expected_return = round((float) $vault->principal + $interest, 2);
        $vault->save();
    }
}
