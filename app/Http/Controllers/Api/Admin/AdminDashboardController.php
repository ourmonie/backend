<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Models\LedgerEntry;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\RewardAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PRD 33/34 - administrative dashboard and reports.
 *
 * Every number on this endpoint is aggregated from ledger entries, member
 * accounts, transactions and reward entries. Nothing is read from a manually
 * maintained statistics table, and nothing is hardcoded.
 */
class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json([
            'members' => $this->memberMetrics(),
            'money' => $this->moneyMetrics(),
            'ledger' => $this->ledgerMetrics(),
            'transactions' => $this->transactionMetrics(),
            'rewards' => $this->rewardMetrics(),
            'revenue' => $this->revenueMetrics(),
            'operations' => $this->operationMetrics(),
            'growth' => $this->growthSeries(),
        ]);
    }

    /* ------------------------------- members ------------------------------- */

    private function memberMetrics(): array
    {
        $byStatus = Member::query()
            ->selectRaw('membership_status, COUNT(*) as total')
            ->groupBy('membership_status')
            ->pluck('total', 'membership_status');

        $byKyc = Member::query()
            ->selectRaw('kyc_status, COUNT(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        $byTier = Member::query()
            ->join('membership_tiers', 'membership_tiers.id', '=', 'members.membership_tier_id')
            ->selectRaw('membership_tiers.name as name, COUNT(*) as total')
            ->groupBy('membership_tiers.name')
            ->orderByDesc('total')
            ->get();

        return [
            'total' => Member::count(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'closed' => (int) ($byStatus['closed'] ?? 0),
            'verified' => (int) ($byKyc['verified'] ?? 0),
            'kyc_incomplete' => Member::where('kyc_status', '!=', 'verified')->count(),
            'new_this_month' => Member::where('created_at', '>=', now()->startOfMonth())->count(),
            'new_last_30d' => Member::where('created_at', '>=', now()->subDays(30))->count(),
            'referred_members' => Member::whereNotNull('referred_by')->count(),
            'by_status' => $byStatus,
            'by_kyc' => $byKyc,
            'by_tier' => $byTier->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->total,
            ]),
        ];
    }

    /* -------------------------------- money -------------------------------- */

    private function moneyMetrics(): array
    {
        $accounts = MemberAccount::query()
            ->selectRaw('type, COUNT(*) as accounts, SUM(balance) as balance, SUM(total_in) as total_in, SUM(total_out) as total_out')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $balance = fn (string $type): float => round((float) ($accounts[$type]->balance ?? 0), 2);

        $wallet = $balance(MemberAccount::TYPE_WALLET);
        $swift = $balance(MemberAccount::TYPE_SWIFT);
        $quest = $balance(MemberAccount::TYPE_QUEST);
        $vault = $balance(MemberAccount::TYPE_VAULT);

        return [
            'wallet_balance' => $wallet,
            'savings_balance' => round($swift + $quest + $vault, 2),
            'total_member_funds' => round($wallet + $swift + $quest + $vault, 2),
            'by_product' => [
                'wallet' => $wallet,
                'swift_save' => $swift,
                'quest_save' => $quest,
                'time_vault' => $vault,
            ],
            'lifetime_in' => round((float) $accounts->sum('total_in'), 2),
            'lifetime_out' => round((float) $accounts->sum('total_out'), 2),
            'account_counts' => $accounts->mapWithKeys(fn ($row, $type) => [$type => (int) $row->accounts]),
        ];
    }

    /* -------------------------------- ledger -------------------------------- */

    private function ledgerMetrics(): array
    {
        $positions = DB::table('ledger_accounts as la')
            ->leftJoin('ledger_entries as le', 'le.ledger_account_id', '=', 'la.id')
            ->select('la.code', 'la.name', 'la.type', 'la.normal_side', 'la.group')
            ->selectRaw("COALESCE(SUM(CASE WHEN le.direction = 'debit' THEN le.amount ELSE 0 END), 0) as debits")
            ->selectRaw("COALESCE(SUM(CASE WHEN le.direction = 'credit' THEN le.amount ELSE 0 END), 0) as credits")
            ->groupBy('la.code', 'la.name', 'la.type', 'la.normal_side', 'la.group')
            ->orderBy('la.code')
            ->get()
            ->map(function ($row) {
                $balance = $row->normal_side === 'debit'
                    ? $row->debits - $row->credits
                    : $row->credits - $row->debits;

                return [
                    'code' => $row->code,
                    'name' => $row->name,
                    'type' => $row->type,
                    'group' => $row->group,
                    'balance' => round((float) $balance, 2),
                    'debits' => round((float) $row->debits, 2),
                    'credits' => round((float) $row->credits, 2),
                ];
            });

        $byType = $positions->groupBy('type')->map(fn ($rows) => round((float) $rows->sum('balance'), 2));

        return [
            'accounts' => $positions,
            'net_by_type' => $byType,
            'cash_position' => round((float) $positions->where('type', 'asset')->sum('balance'), 2),
            'member_liability' => round((float) $positions->where('type', 'liability')->sum('balance'), 2),
            'integrity' => $this->ledgerIntegrity(),
        ];
    }

    /**
     * PRD 42/45 - double-entry invariant, recomputed on demand from the entries.
     */
    private function ledgerIntegrity(): array
    {
        $totals = LedgerEntry::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) as debits")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) as credits")
            ->first();

        $debits = round((float) $totals->debits, 2);
        $credits = round((float) $totals->credits, 2);

        return [
            'debits' => $debits,
            'credits' => $credits,
            'difference' => round($debits - $credits, 2),
            'balanced' => abs($debits - $credits) < 0.005,
            'entries' => LedgerEntry::count(),
        ];
    }

    /* ----------------------------- transactions ----------------------------- */

    private function transactionMetrics(): array
    {
        $byStatus = Transaction::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byType = Transaction::query()
            ->selectRaw('type, COUNT(*) as total, SUM(amount) as volume, SUM(fee_amount) as fees')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get();

        $total = (int) $byStatus->sum();
        $completed = (int) ($byStatus['completed'] ?? $byStatus['success'] ?? $byStatus['posted'] ?? 0);

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'by_type' => $byType->map(fn ($row) => [
                'type' => $row->type,
                'count' => (int) $row->total,
                'volume' => round((float) $row->volume, 2),
                'fees' => round((float) $row->fees, 2),
            ]),
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 100.0,
            'volume_30d' => round((float) Transaction::where('created_at', '>=', now()->subDays(30))->sum('amount'), 2),
            'count_30d' => Transaction::where('created_at', '>=', now()->subDays(30))->count(),
            'pending' => Transaction::where('status', 'pending')->count(),
            'failed' => Transaction::whereIn('status', ['failed', 'reversed'])->count(),
        ];
    }

    /* ------------------------------- rewards ------------------------------- */

    private function rewardMetrics(): array
    {
        $totals = RewardAccount::query()
            ->selectRaw('COALESCE(SUM(points_balance),0) as balance, COALESCE(SUM(lifetime_earned),0) as earned, COALESCE(SUM(lifetime_redeemed),0) as redeemed, COALESCE(SUM(pending_points),0) as pending')
            ->first();

        $conversions = \App\Models\RewardEntry::where('entry_type', 'redeem')->count();

        return [
            'points_in_circulation' => (int) $totals->balance,
            'lifetime_earned' => (int) $totals->earned,
            'lifetime_redeemed' => (int) $totals->redeemed,
            'pending_points' => (int) $totals->pending,
            'holders' => RewardAccount::where('points_balance', '>', 0)->count(),
            'conversions' => $conversions,
        ];
    }

    /* -------------------------------- revenue -------------------------------- */

    private function revenueMetrics(): array
    {
        $positions = DB::table('ledger_accounts as la')
            ->leftJoin('ledger_entries as le', 'le.ledger_account_id', '=', 'la.id')
            ->where('la.type', 'revenue')
            ->select('la.code', 'la.name', 'la.group')
            ->selectRaw("COALESCE(SUM(CASE WHEN le.direction = 'credit' THEN le.amount ELSE -le.amount END), 0) as balance")
            ->groupBy('la.code', 'la.name', 'la.group')
            ->orderBy('la.code')
            ->get();

        $expense = DB::table('ledger_accounts as la')
            ->leftJoin('ledger_entries as le', 'le.ledger_account_id', '=', 'la.id')
            ->where('la.type', 'expense')
            ->select('la.code', 'la.name', 'la.group')
            ->selectRaw("COALESCE(SUM(CASE WHEN le.direction = 'debit' THEN le.amount ELSE -le.amount END), 0) as balance")
            ->groupBy('la.code', 'la.name', 'la.group')
            ->orderBy('la.code')
            ->get();

        $income = round((float) $positions->sum('balance'), 2);
        $spend = round((float) $expense->sum('balance'), 2);

        return [
            'income' => $income,
            'expense' => $spend,
            'net' => round($income - $spend, 2),
            'income_by_account' => $positions->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'group' => $row->group,
                'amount' => round((float) $row->balance, 2),
            ]),
            'expense_by_account' => $expense->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'group' => $row->group,
                'amount' => round((float) $row->balance, 2),
            ]),
        ];
    }

    /* ------------------------------ operations ------------------------------ */

    private function operationMetrics(): array
    {
        return [
            'kyc_open' => KycRecord::whereIn('status', KycRecord::OPEN_STATUSES)->count(),
            'kyc_unresolved' => Member::where('kyc_status', '!=', 'verified')
                ->where('membership_status', '!=', 'closed')
                ->count(),
            'active_holds' => \App\Models\AccountHold::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'pending_memberships' => Member::where('membership_status', 'pending')->count(),
            'pending_adjustments' => DB::table('adjustment_requests')->where('status', 'pending')->count(),
            'unread_notifications' => \App\Models\MemberNotification::where('is_read', false)->count(),
        ];
    }

    /* -------------------------------- growth -------------------------------- */

    private function growthSeries(): array
    {
        $from = now()->startOfMonth()->subMonths(11);

        $members = Member::query()
            ->where('created_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('total', 'ym');

        $transactions = Transaction::query()
            ->where('created_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total, SUM(amount) as volume")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        // Savings net movement, straight from ledger postings on non-wallet
        // member accounts - a real cash-flow series, not a stored figure.
        $savings = LedgerEntry::query()
            ->join('member_accounts as ma', 'ma.id', '=', 'ledger_entries.member_account_id')
            ->where('ma.type', '!=', MemberAccount::TYPE_WALLET)
            ->where('ledger_entries.created_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(ledger_entries.created_at, '%Y-%m') as ym")
            ->selectRaw("SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount ELSE -ledger_entries.amount END) as net")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('net', 'ym');

        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $key = $from->copy()->addMonths($i)->format('Y-%m');

            $months[] = [
                'month' => $key,
                'label' => $from->copy()->addMonths($i)->format('M Y'),
                'new_members' => (int) ($members[$key] ?? 0),
                'transactions' => (int) ($transactions[$key]->total ?? 0),
                'volume' => round((float) ($transactions[$key]->volume ?? 0), 2),
                'savings_net' => round((float) ($savings[$key] ?? 0), 2),
            ];
        }

        return $months;
    }
}
