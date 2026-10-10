<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\LedgerEntry;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Everything the member dashboard renders, in one round trip.
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        if ($member === null) {
            return response()->json(['message' => 'We could not find your member profile. Please contact support.'], 404);
        }

        $member->load(['accounts', 'wallet.account', 'rewardAccount', 'tier']);

        $accounts = $member->accounts->keyBy('type');
        $wallet = $accounts->get(MemberAccount::TYPE_WALLET);
        $walletBalance = round((float) $wallet?->balance, 2);

        // NOTE: never use Collection::only() on an Eloquent collection that has
        // been keyBy()'d - it matches on model IDs, not on the new key.
        $savingsBalances = $this->savingsTotals($accounts);
        $totalSavings = round(array_sum($savingsBalances), 2);
        $reward = $member->rewardAccount;

        return response()->json([
            'member' => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'member_number' => $member->member_number,
                'referral_code' => $member->referral_code,
                'membership_status' => $member->membership_status,
                'kyc_status' => $member->kyc_status,
                'kyc_level' => $member->kyc_level,
                'member_since' => optional($member->member_since)->toDateString(),
                'tier' => $member->tier ? [
                    'code' => $member->tier->code,
                    'name' => $member->tier->name,
                    'rewards_multiplier' => (float) $member->tier->rewards_multiplier,
                    'benefits' => $member->tier->benefits,
                ] : null,
                'avatar' => $member->avatar ?? $member->user?->avatar,
            ],

            'balances' => [
                'wallet' => $walletBalance,
                'total_savings' => $totalSavings,
                'swiftsave' => $savingsBalances['swift_save'] ?? 0,
                'questsave' => $savingsBalances['quest_save'] ?? 0,
                'timevault' => $savingsBalances['time_vault'] ?? 0,
                'cooperative_share' => round($walletBalance + $totalSavings, 2),
                'currency' => 'NGN',
            ],

            'wallet' => [
                'account_number' => $member->wallet?->account_number,
                'account_name' => $member->wallet?->account_name ?? $member->user?->name,
                'bank_name' => $member->wallet?->bank_name ?? 'OurMonie',
                'status' => $member->wallet?->status,
            ],

            'rewards' => [
                'points' => (int) ($reward?->points_balance ?? 0),
                'cash_value' => $reward?->cashValue() ?? 0,
                'convertible' => $reward?->isConvertible() ?? false,
                'conversion_threshold' => $reward?->lowestConversionThreshold() ?? 0,
                'lifetime_earned' => (int) ($reward?->lifetime_earned ?? 0),
            ],

            'growth' => $this->growth($member, $walletBalance, $totalSavings, $reward?->cashValue() ?? 0),

            'allocation' => $this->allocation($accounts, $walletBalance, $totalSavings),

            'recent_transactions' => $this->recentTransactions($member),

            'membership' => [
                'status' => $member->membership_status,
                'member_since' => optional($member->member_since)->toDateString(),
                'tier' => $member->tier?->name,
                'referral_code' => $member->referral_code,
                'verified' => $member->isVerified(),
                'total_contributions' => $this->lifetimeContributions($member),
                'interest_earned' => $this->lifetimeInterest($member),
            ],

            'alerts' => $this->alerts($member),

            'announcements' => Announcement::visible()->limit(3)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'category' => $a->category,
                    'severity' => $a->severity,
                    'pinned' => (bool) $a->pinned,
                    'published_at' => optional($a->published_at)->diffForHumans(),
                ]),

            'unread_notifications' => $member->notifications()->where('is_read', false)->count(),
        ]);
    }

    /**
     * Month-over-month savings series for the dashboard chart.
     */
    public function chart(Request $request): JsonResponse
    {
        $member = $request->user()->member;
        $savingsIds = MemberAccount::where('member_id', $member->id)
            ->whereIn('type', [MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT])
            ->pluck('id');

        $points = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonthsNoOverflow($i);
            $cutoff = $month->copy()->endOfMonth();

            $base = LedgerEntry::whereIn('member_account_id', $savingsIds)
                ->where('created_at', '<=', $cutoff);

            $credit = (float) (clone $base)->where('direction', 'credit')->sum('amount');
            $debit = (float) (clone $base)->where('direction', 'debit')->sum('amount');

            $points[] = [
                'month' => $month->format('M'),
                'label' => $month->format('M Y'),
                'value' => round($credit - $debit, 2),
            ];
        }

        return response()->json(['points' => $points]);
    }

    /* ------------------------------- internals ------------------------------ */

    /**
     * Savings balances keyed by member account type.
     *
     * Deliberately avoids Collection::only() because on an Eloquent collection
     * that has been keyBy()'d it still matches on model IDs.
     */
    private function savingsTotals($accounts): array
    {
        $totals = [];
        foreach ([MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT] as $type) {
            $totals[$type] = round((float) $accounts->get($type)?->balance, 2);
        }

        return $totals;
    }

    private function growth(Member $member, float $wallet, float $savings, float $rewards): array
    {
        $cutoff = now()->subMonth()->endOfMonth();

        return [
            'wallet' => $this->percentChange($wallet, $this->balanceAt($member, MemberAccount::TYPE_WALLET, $cutoff)),
            'savings' => $this->percentChange($savings, $this->savingsAt($member, $cutoff)),
            'rewards' => $this->percentChange($rewards, $this->rewardsAt($member, $cutoff)),
        ];
    }

    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Reward cash value at a historic point, derived from posted entries.
     */
    private function rewardsAt(Member $member, $cutoff): float
    {
        $lastEntry = \App\Models\RewardEntry::where('member_id', $member->id)
            ->where('created_at', '<=', $cutoff)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($lastEntry === null || $lastEntry->balance_after === null) {
            return 0.0;
        }

        return \App\Models\RewardConversionTier::cashValueFor((int) $lastEntry->balance_after);
    }

    private function balanceAt(Member $member, string $type, $cutoff): float
    {
        $account = MemberAccount::where('member_id', $member->id)->where('type', $type)->first();
        if ($account === null) {
            return 0;
        }

        $credit = LedgerEntry::where('member_account_id', $account->id)->where('created_at', '<=', $cutoff)->where('direction', 'credit')->sum('amount');
        $debit = LedgerEntry::where('member_account_id', $account->id)->where('created_at', '<=', $cutoff)->where('direction', 'debit')->sum('amount');

        return round((float) $credit - (float) $debit, 2);
    }

    private function savingsAt(Member $member, $cutoff): float
    {
        $ids = MemberAccount::where('member_id', $member->id)
            ->whereIn('type', [MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT])
            ->pluck('id');

        $credit = LedgerEntry::whereIn('member_account_id', $ids)->where('created_at', '<=', $cutoff)->where('direction', 'credit')->sum('amount');
        $debit = LedgerEntry::whereIn('member_account_id', $ids)->where('created_at', '<=', $cutoff)->where('direction', 'debit')->sum('amount');

        return round((float) $credit - (float) $debit, 2);
    }

    /**
     * Where the member's own money sits right now (PRD section 7).
     */
    private function allocation($accounts, float $wallet, float $savings): array
    {
        $totals = $this->savingsTotals($accounts);

        $segments = [
            ['key' => 'wallet', 'label' => 'Wallet', 'value' => $wallet, 'color' => 'primary'],
            ['key' => 'swiftsave', 'label' => 'SwiftSave', 'value' => $totals[MemberAccount::TYPE_SWIFT], 'color' => 'sky'],
            ['key' => 'questsave', 'label' => 'QuestSave', 'value' => $totals[MemberAccount::TYPE_QUEST], 'color' => 'violet'],
            ['key' => 'timevault', 'label' => 'TimeVault', 'value' => $totals[MemberAccount::TYPE_VAULT], 'color' => 'amber'],
        ];

        $total = max(0.01, array_sum(array_column($segments, 'value')));

        foreach ($segments as &$segment) {
            $segment['percent'] = round(($segment['value'] / $total) * 100, 1);
        }
        unset($segment);

        return [
            'total' => round($total, 2),
            'segments' => $segments,
            'savings_percent' => $savings > 0 ? round(($savings / $total) * 100, 1) : 0,
        ];
    }

    private function recentTransactions(Member $member): array
    {
        return Transaction::where('member_id', $member->id)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->orderByDesc('occurred_at')
            ->limit(6)
            ->get()
            ->map(fn (Transaction $t) => [
                'reference' => $t->reference,
                'type' => $t->type,
                'direction' => $t->direction,
                'amount' => (float) $t->amount,
                'fee_amount' => (float) $t->fee_amount,
                'description' => $t->description,
                'counterparty' => $t->counterparty,
                'status' => $t->status,
                'channel' => $t->channel,
                'occurred_at' => optional($t->occurred_at)->toDateTimeString(),
                'occurred_label' => optional($t->occurred_at)->format('d M Y, h:i A'),
            ])
            ->all();
    }

    private function lifetimeContributions(Member $member): float
    {
        $ids = MemberAccount::where('member_id', $member->id)
            ->whereIn('type', [MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT])
            ->pluck('id');

        return round((float) LedgerEntry::whereIn('member_account_id', $ids)
            ->where('direction', 'credit')
            ->whereHas('transaction', fn ($q) => $q->where('type', '!=', Transaction::INTEREST))
            ->sum('amount'), 2);
    }

    private function lifetimeInterest(Member $member): float
    {
        return round((float) LedgerEntry::where('member_id', $member->id)
            ->where('direction', 'credit')
            ->whereHas('transaction', fn ($q) => $q->where('type', Transaction::INTEREST))
            ->sum('amount'), 2);
    }

    private function alerts(Member $member): array
    {
        $alerts = [];

        if ($member->kyc_status !== 'verified') {
            $alerts[] = ['type' => 'kyc', 'severity' => 'warning', 'message' => 'Complete your KYC to unlock full account limits.'];
        }

        if ($member->hasBlockingHold()) {
            $alerts[] = ['type' => 'hold', 'severity' => 'critical', 'message' => 'Your account is currently restricted. Contact support.'];
        }

        if ($member->wallet === null) {
            $alerts[] = ['type' => 'wallet', 'severity' => 'info', 'message' => 'Your dedicated account number is being prepared.'];
        }

        return $alerts;
    }
}
