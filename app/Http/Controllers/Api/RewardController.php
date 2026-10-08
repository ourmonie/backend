<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\MemberAccount;
use App\Models\RewardConversionTier;
use App\Models\RewardEntry;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;
        $account = $member->rewardAccount()->firstOrCreate(['member_id' => $member->id]);

        $multiplier = (float) ($member->tier?->rewards_multiplier ?? 1);

        return response()->json([
            'points_balance' => (int) $account->points_balance,
            'lifetime_earned' => (int) $account->lifetime_earned,
            'lifetime_redeemed' => (int) $account->lifetime_redeemed,
            'cash_value' => $account->cashValue(),
            'convertible' => $account->isConvertible(),
            'conversion_threshold' => $account->lowestConversionThreshold(),
            'points_to_threshold' => max(0, $account->lowestConversionThreshold() - (int) $account->points_balance),
            'tier_multiplier' => $multiplier,
            'tiers' => RewardConversionTier::where('is_active', true)->orderBy('min_points')->get()->map(fn ($t) => [
                'min_points' => (int) $t->min_points,
                'max_points' => $t->max_points !== null ? (int) $t->max_points : null,
                'cash_value' => (float) $t->cash_value,
                'label' => $t->label,
            ]),
            'rules' => \App\Models\RewardRule::where('is_active', true)->get()->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'activity' => $r->activity,
                'points' => (float) $r->points,
                'points_type' => $r->points_type,
            ]),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        $entries = RewardEntry::where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($entries->items())->map(fn (RewardEntry $e) => [
                'entry_type' => $e->entry_type,
                'points' => (int) $e->points,
                'balance_after' => $e->balance_after !== null ? (int) $e->balance_after : null,
                'activity' => $e->activity,
                'description' => $e->description,
                'created_at' => optional($e->created_at)->toDateTimeString(),
                'created_label' => optional($e->created_at)->format('d M Y, h:i A'),
            ]),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * Convert points to wallet cash using the configured tiers.
     */
    public function convert(Request $request): JsonResponse
    {
        $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'pin' => ['nullable', 'string', 'digits:4,6'],
        ]);

        $member = $request->user()->member;
        $account = $member->rewardAccount()->firstOrCreate(['member_id' => $member->id]);

        if (! $account->isConvertible()) {
            throw ValidationException::withMessages([
                'points' => sprintf(
                    'You need at least %s points to convert. You currently have %s.',
                    number_format($account->lowestConversionThreshold()),
                    number_format((int) $account->points_balance)
                ),
            ]);
        }

        $points = (int) $request->input('points');

        if ($points > (int) $account->points_balance) {
            throw ValidationException::withMessages(['points' => ['You do not have that many points.']]);
        }

        // Value is derived from the conversion tiers, never hardcoded.
        $fullValue = $account->cashValue();
        $cash = round($fullValue * ($points / max(1, (int) $account->points_balance)), 2);

        if ($cash <= 0) {
            throw ValidationException::withMessages(['points' => ['These points do not convert to a positive amount yet.']]);
        }

        $transaction = DB::transaction(function () use ($account, $member, $points, $cash) {
            $tx = $this->walletService->convertRewards($member, $cash, $points, $cash / max(1, $points));

            RewardEntry::create([
                'reward_account_id' => $account->id,
                'member_id' => $member->id,
                'entry_type' => 'redeem',
                'points' => -$points,
                'balance_after' => (int) $account->points_balance - $points,
                'activity' => 'conversion',
                'reference' => $tx->reference,
                'description' => sprintf('%s points converted to ₦%s', number_format($points), number_format($cash, 2)),
                'transaction_id' => $tx->id,
            ]);

            $account->decrement('points_balance', $points);
            $account->increment('lifetime_redeemed', $points);

            return $tx;
        });

        return response()->json([
            'message' => 'Points converted to cash.',
            'cash' => $cash,
            'points_redeemed' => $points,
            'reference' => $transaction->reference,
            'wallet_balance' => round((float) $member->accounts()->where('type', MemberAccount::TYPE_WALLET)->value('balance'), 2),
            'points_remaining' => (int) $account->fresh()->points_balance,
        ], 201);
    }
}
