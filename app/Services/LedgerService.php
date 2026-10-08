<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MemberAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single, authoritative way money moves in OurMonie.
 *
 * Every financial movement is posted as an immutable double-entry pair:
 *
 *   - sum(debits) MUST equal sum(credits)
 *   - member account balances are DERIVED from these postings
 *   - posting happens inside one DB transaction with row locks
 *   - references are unique, so replaying a post is a no-op
 *
 * A balance is never assigned directly anywhere else in the codebase.
 */
class LedgerService
{
    /**
     * Post a balanced set of ledger lines and return the transaction.
     *
     * Line shape (either `account` or `member_account_id` is required):
     *   [
     *     'direction'         => 'debit'|'credit',
     *     'amount'            => 1000.00,
     *     'account'           => LedgerAccount::CASH_AT_BANK,   // code or model
     *     'member_account_id' => 12,                            // implies credit to member liability
     *     'memo'              => 'optional',
     *   ]
     *
     * Convenience keys `debit` / `credit` may be used instead of
     * direction+amount:
     *   ['account' => LedgerAccount::CASH_AT_BANK, 'debit' => 1000]
     *
     * @param  array{
     *   reference?:string, type:string, member_id?:int, channel?:string,
     *   source?:string, description?:string, narration?:string,
     *   counterparty?:string, provider_reference?:string, status?:string,
     *   member_account_id?:int, created_by?:int, occurred_at?:\DateTimeInterface,
     *   meta?:array, lines:array<int,array>
     * }  $data
     */
    public function post(array $data): Transaction
    {
        $reference = $data['reference'] ?? null;

        // Idempotency guard: an already-recorded reference is a replay.
        if ($reference !== null) {
            $existing = Transaction::where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $lines = $this->normalizeLines($data['lines'] ?? []);
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($data, $lines, $reference) {
            $transaction = Transaction::create([
                'reference' => $reference ?? Transaction::generateReference(),
                'member_id' => $data['member_id'] ?? null,
                'type' => $data['type'],
                'direction' => $this->dominantDirection($lines, $data['member_account_id'] ?? null),
                'amount' => $data['amount'] ?? $this->grossAmount($lines),
                'fee_amount' => (float) ($data['fee_amount'] ?? 0),
                'net_amount' => $data['amount'] ?? $this->grossAmount($lines),
                'currency' => $data['currency'] ?? 'NGN',
                'status' => $data['status'] ?? Transaction::STATUS_COMPLETED,
                'channel' => $data['channel'] ?? 'internal',
                'source' => $data['source'] ?? 'internal',
                'member_account_id' => $data['member_account_id'] ?? null,
                'description' => $data['description'] ?? null,
                'narration' => $data['narration'] ?? null,
                'counterparty' => $data['counterparty'] ?? null,
                'provider_reference' => $data['provider_reference'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'posted_at' => now(),
                'occurred_at' => $data['occurred_at'] ?? now(),
                'meta' => $data['meta'] ?? null,
            ]);

            // Lock the member accounts touched here, in a stable order,
            // so concurrent postings cannot interleave and drift balances.
            $memberAccountIds = collect($lines)
                ->pluck('member_account_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $locked = collect();
            foreach ($memberAccountIds as $id) {
                $locked->push(
                    MemberAccount::whereKey($id)->lockForUpdate()->firstOrFail()
                );
            }
            $lockedById = $locked->keyBy('id');

            foreach ($lines as $line) {
                $account = $this->resolveLedgerAccount($line);
                $memberAccount = null;

                if (! empty($line['member_account_id'])) {
                    $memberAccount = $lockedById->get($line['member_account_id']);
                }

                $entry = LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'ledger_account_id' => $account->id,
                    'member_id' => $memberAccount?->member_id ?? $transaction->member_id,
                    'member_account_id' => $memberAccount?->id,
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'currency' => $transaction->currency,
                    'memo' => $line['memo'] ?? null,
                ]);

                if ($memberAccount !== null) {
                    $this->applyToMemberAccount($memberAccount, $line);
                    $entry->forceFill(['balance_after' => $memberAccount->balance])->save();
                }
            }

            return $transaction->refresh();
        });
    }

    /* ---------------------------------------------------------------------- */
    /* Internals                                                              */
    /* ---------------------------------------------------------------------- */

    private function normalizeLines(array $lines): array
    {
        return array_map(function ($line) {
            if (isset($line['debit'])) {
                $line['direction'] = 'debit';
                $line['amount'] = $line['debit'];
                unset($line['debit']);
            } elseif (isset($line['credit'])) {
                $line['direction'] = 'credit';
                $line['amount'] = $line['credit'];
                unset($line['credit']);
            }

            if (! in_array($line['direction'] ?? null, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Each ledger line needs direction "debit" or "credit".');
            }

            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Ledger line amounts must be greater than zero.');
            }
            $line['amount'] = $amount;

            if (empty($line['account']) && empty($line['member_account_id'])) {
                throw new InvalidArgumentException('Each ledger line needs an account or a member_account_id.');
            }

            return $line;
        }, $lines);
    }

    private function assertBalanced(array $lines): void
    {
        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $line) {
            if ($line['direction'] === 'debit') {
                $debits += $line['amount'];
            } else {
                $credits += $line['amount'];
            }
        }

        if (round($debits, 2) !== round($credits, 2)) {
            throw new InvalidArgumentException(sprintf(
                'Unbalanced ledger posting: debits %.2f != credits %.2f.',
                $debits,
                $credits
            ));
        }
    }

    private function resolveLedgerAccount(array $line): LedgerAccount
    {
        if (! empty($line['member_account_id'])) {
            $memberAccount = MemberAccount::find($line['member_account_id']);
            if ($memberAccount === null) {
                throw new InvalidArgumentException('Unknown member account.');
            }
            if ($memberAccount->ledger_account_id !== null) {
                return LedgerAccount::findOrFail($memberAccount->ledger_account_id);
            }
        }

        $account = $line['account'] ?? null;

        if ($account instanceof LedgerAccount) {
            return $account;
        }

        if (is_string($account)) {
            return LedgerAccount::byCode($account);
        }

        if (is_int($account)) {
            return LedgerAccount::findOrFail($account);
        }

        // Member accounts without an explicit ledger account fall back to
        // the product-class liability account.
        return LedgerAccount::byCode(
            $this->fallbackCodeFor($line['member_account_id'] ?? null)
        );
    }

    private function fallbackCodeFor(?int $memberAccountId): string
    {
        if ($memberAccountId === null) {
            return LedgerAccount::MEMBER_SAVINGS;
        }

        $type = MemberAccount::whereKey($memberAccountId)->value('type');

        return $type === MemberAccount::TYPE_WALLET
            ? LedgerAccount::MEMBER_WALLET
            : LedgerAccount::MEMBER_SAVINGS;
    }

    /**
     * Member accounts are liabilities of the cooperative: credits grow the
     * balance, debits shrink it.
     */
    private function applyToMemberAccount(MemberAccount $account, array $line): void
    {
        $amount = $line['amount'];

        $newBalance = $line['direction'] === 'credit'
            ? (float) $account->balance + $amount
            : (float) $account->balance - $amount;

        if ($newBalance < -0.009) {
            throw new InvalidArgumentException(sprintf(
                'Insufficient balance in %s account: available %.2f, attempted %.2f.',
                $account->label(),
                (float) $account->balance,
                $amount
            ));
        }

        $account->balance = max(0, round($newBalance, 2));
        $account->total_in = round((float) $account->total_in + ($line['direction'] === 'credit' ? $amount : 0), 2);
        $account->total_out = round((float) $account->total_out + ($line['direction'] === 'debit' ? $amount : 0), 2);
        $account->save();
    }

    /**
     * The transaction direction is always expressed as it affected the
     * MEMBER's money, not the counterparty leg of the entry pair.
     *
     * Funding the wallet is therefore a credit, withdrawing is a debit,
     * regardless of which suspense accounts are used on the far side.
     * Only entries with no member leg at all (provider settlement,
     * internal bookkeeping) fall back to a neutral credit.
     */
    private function dominantDirection(array $lines, ?int $primaryAccountId): string
    {
        $ordered = collect($lines)->sortBy(
            fn (array $line) => ($line['member_account_id'] ?? null) === $primaryAccountId ? 0 : 1
        );

        foreach ($ordered as $line) {
            if (! empty($line['member_account_id'])) {
                return $line['direction'];
            }
        }

        return 'credit';
    }

    /**
     * The headline amount: the value credited to the member's primary account.
     */
    private function grossAmount(array $lines): float
    {
        foreach ($lines as $line) {
            if (! empty($line['member_account_id']) && $line['direction'] === 'credit') {
                return $line['amount'];
            }
        }

        return max(array_column($lines, 'amount'));
    }
}
