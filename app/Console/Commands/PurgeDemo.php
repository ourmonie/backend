<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remove the seeded demonstration records from an instance.
 *
 * Demo rows exist only to preview the shape of the interface. They are not
 * real money and not real people, but they sit in the same tables as both,
 * so removing them has to respect the same double-entry rules as everything
 * else: whole balanced transactions are deleted rather than balances being
 * adjusted, which leaves the books balanced instead of quietly rewritten.
 *
 * Three things make this safe to run on a live instance:
 *
 *   1. Only users on the seeded test domain are considered, so a member who
 *      registered with a real address cannot be caught by it.
 *   2. Any candidate holding a role is refused outright. Staff accounts are
 *      frequently created on the test domain during setup, and an
 *      administrator losing their own access mid-cleanup is not an outcome
 *      this command should be capable of producing.
 *   3. The ledger is measured before and after. If the deletion moves the
 *      books off balance the command reports it as a failure rather than
 *      declaring success over a broken ledger.
 */
class PurgeDemo extends Command
{
    /** The domain every seeded demonstration account was created on. */
    private const DOMAIN = '@ourmonie.test';

    protected $signature = 'ourmonie:purge-demo
        {--force : Skip the confirmation prompt}
        {--dry-run : Report what would be removed without deleting anything}
        {--settlements : Also remove the seeded bank settlements, which belong to no member}';

    protected $description = 'Delete seeded demonstration members, their users, and every transaction they own';

    public function handle(): int
    {
        $users = $this->candidates();

        $memberIds = $users->pluck('member_id')->filter()->values();
        $userIds = $users->pluck('id')->values();
        $accountIds = DB::table('member_accounts')->whereIn('member_id', $memberIds)->pluck('id');
        $transactionIds = DB::table('transactions')->whereIn('member_id', $memberIds)->pluck('id');

        // Settlements move money between the platform's own accounts, so they
        // belong to no member and are not reached by deleting one. The seeded
        // ones can be told apart from a real settlement by the absence of a
        // provider reference: an actual payout always records where it came
        // from, and a row invented to fill a chart never does.
        $settlementIds = $this->option('settlements')
            ? DB::table('transactions')
                ->whereNull('member_id')
                ->where('type', 'settlement')
                ->whereNull('provider_reference')
                ->pluck('id')
            : collect();

        if ($users->isEmpty() && $settlementIds->isEmpty()) {
            $this->info('No demonstration records found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->report($users, $memberIds, $accountIds, $transactionIds, $settlementIds);

        if ($this->option('dry-run')) {
            $this->comment('Dry run. Nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these records permanently?')) {
            $this->comment('Cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        $before = $this->drift();
        $ledgerBefore = (int) DB::table('ledger_entries')->count();

        DB::transaction(function () use ($userIds, $memberIds, $accountIds, $transactionIds, $settlementIds) {
            // Children before parents, and the two tables that only null out
            // on a member delete are emptied explicitly. Leaving the cascade
            // to handle those would orphan the transaction and audit rows,
            // which still count on the dashboard long after the member went.
            DB::table('ledger_entries')
                ->where(function ($query) use ($accountIds, $memberIds) {
                    $query->whereIn('member_account_id', $accountIds)
                        ->orWhereIn('member_id', $memberIds);
                })
                ->delete();

            DB::table('audit_logs')->whereIn('member_id', $memberIds)->delete();

            $allTransactionIds = $transactionIds->merge($settlementIds)->values();

            DB::table('payment_events')->whereIn('transaction_id', $allTransactionIds)->delete();
            DB::table('transactions')->whereIn('id', $allTransactionIds)->delete();

            // Tokens are a morph, so there is no foreign key to cascade them.
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            // Cascades to members, and from there to accounts, wallets,
            // savings, rewards, goals, vaults, KYC, notifications and holds.
            DB::table('users')->whereIn('id', $userIds)->delete();
        });

        $after = $this->drift();

        if ($after !== $before) {
            $this->error(sprintf(
                'The ledger no longer balances. It stood at %s and now stands at %s.',
                number_format($before, 2),
                number_format($after, 2)
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Deleted %d user(s), %d transaction(s) and %d ledger entry(s).',
            $userIds->count(),
            $transactionIds->count() + $settlementIds->count(),
            $ledgerBefore - (int) DB::table('ledger_entries')->count()
        ));
        $this->info('Ledger still balances at '.number_format($after, 2).' drift.');

        return self::SUCCESS;
    }

    /**
     * Seeded demonstration users, excluding anyone holding a role.
     */
    private function candidates(): Collection
    {
        return DB::table('users')
            ->join('members', 'members.user_id', '=', 'users.id')
            ->leftJoin('role_user', 'role_user.user_id', '=', 'users.id')
            ->whereRaw('lower(users.email) like ?', ['%'.self::DOMAIN])
            ->whereNull('role_user.id')
            ->select('users.id', 'users.email', 'users.name', 'members.id as member_id', 'members.member_number')
            ->orderBy('users.id')
            ->get();
    }

    private function report(Collection $users, Collection $memberIds, Collection $accountIds, Collection $transactionIds, Collection $settlementIds): void
    {
        $this->newLine();

        if ($users->isNotEmpty()) {
            $this->line('Demonstration accounts found:');

            foreach ($users as $user) {
                $held = (float) DB::table('member_accounts')
                    ->where('member_id', $user->member_id)
                    ->sum('balance');

                $this->line(sprintf(
                    '  %-11s %-32s %-18s ₦%s held',
                    $user->member_number ?? 'n/a',
                    $user->email,
                    $user->name ?? '',
                    number_format($held, 2)
                ));
            }

            $this->newLine();
            $this->line(sprintf(
                'Will delete: %d user(s), %d transaction(s), %d account(s), %d ledger entry(s).',
                $users->count(),
                $transactionIds->count(),
                $accountIds->count(),
                DB::table('ledger_entries')->whereIn('member_account_id', $accountIds)->count()
            ));
        }

        if ($settlementIds->isNotEmpty()) {
            $value = (float) DB::table('transactions')->whereIn('id', $settlementIds)->sum('amount');

            $this->newLine();
            $this->line(sprintf(
                'Also deleting %d seeded settlement(s) worth ₦%s, which belong to no member.',
                $settlementIds->count(),
                number_format($value, 2)
            ));
        }
    }

    /**
     * Sum of debits less sum of credits across the entire ledger.
     *
     * A balanced book stands at zero. Deletion is only acceptable if it
     * leaves that figure untouched, which is why every step above removes
     * whole balanced transactions rather than adjusting balances.
     */
    private function drift(): float
    {
        $entry = DB::table('ledger_entries')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN direction = 'debit'  THEN amount END), 0)
              - COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount END), 0) as drift
            ")
            ->value('drift');

        return round((float) $entry, 2);
    }
}
