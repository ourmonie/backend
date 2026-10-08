<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsProduct;
use App\Models\SavingsVault;
use App\Services\AccountProvisioner;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavingsController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AccountProvisioner $provisioner,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        $accounts = $member->accounts()->get()->keyBy('type');
        $products = SavingsProduct::where('is_active', true)->orderBy('sort_order')->get();

        return response()->json([
            'products' => $products->map(fn (SavingsProduct $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'type' => $p->type,
                'description' => $p->description,
                'interest_rate' => (float) $p->interest_rate,
                'interest_period' => $p->interest_period,
                'balance' => round((float) $accounts->get($this->typeFor($p->type))?->balance, 2),
                'min_amount' => (float) $p->min_amount,
                'max_amount' => $p->max_amount !== null ? (float) $p->max_amount : null,
                'allowed_terms' => $p->allowed_terms,
                'early_withdrawal_allowed' => (bool) $p->early_withdrawal_allowed,
                'penalty_type' => $p->penalty_type,
                'penalty_value' => (float) $p->penalty_value,
            ]),
            'totals' => [
                'swiftsave' => round((float) $accounts->get(MemberAccount::TYPE_SWIFT)?->balance, 2),
                'questsave' => round((float) $accounts->get(MemberAccount::TYPE_QUEST)?->balance, 2),
                'timevault' => round((float) $accounts->get(MemberAccount::TYPE_VAULT)?->balance, 2),
                'total' => round($accounts->whereIn('type', [
                    MemberAccount::TYPE_SWIFT, MemberAccount::TYPE_QUEST, MemberAccount::TYPE_VAULT,
                ])->sum(fn ($a) => (float) $a->balance), 2),
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function goals(Request $request): JsonResponse
    {
        $goals = $request->user()->member->goals()->with('product')->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $goals->map(fn (SavingsGoal $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'category' => $g->category,
                'target_amount' => (float) $g->target_amount,
                'current_amount' => (float) $g->current_amount,
                'remaining' => $g->remaining(),
                'target_date' => optional($g->target_date)->toDateString(),
                'progress' => $g->progressPercent(),
                'status' => $g->status,
                'interest_rate' => (float) $g->interest_rate,
                'days_left' => $g->target_date ? (int) ceil(abs(now()->diffInDays($g->target_date, false))) : null,
            ]),
        ]);
    }

    public function storeGoal(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'target_amount' => ['required', 'numeric', 'min:500'],
            'target_date' => ['nullable', 'date', 'after:today'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $product = SavingsProduct::where('code', 'questsave')->where('is_active', true)->firstOrFail();
        $account = $this->provisioner->accountFor($member, MemberAccount::TYPE_QUEST);

        $goal = $member->goals()->create([
            'savings_product_id' => $product->id,
            'member_account_id' => $account->id,
            'name' => $request->input('name'),
            'category' => $request->input('category'),
            'target_amount' => $request->input('target_amount'),
            'current_amount' => 0,
            'target_date' => $request->input('target_date'),
            'interest_rate' => $product->interest_rate,
            'status' => 'active',
        ]);

        return response()->json(['message' => 'Goal created.', 'goal' => $goal], 201);
    }

    public function vaults(Request $request): JsonResponse
    {
        $vaults = $request->user()->member->vaults()->with('product')->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $vaults->map(fn (SavingsVault $v) => [
                'id' => $v->id,
                'vault_number' => $v->vault_number,
                'principal' => (float) $v->principal,
                'term_days' => $v->term_days,
                'interest_rate' => (float) $v->interest_rate,
                'expected_return' => (float) $v->expected_return,
                'interest_earned' => (float) $v->interest_earned,
                'starts_on' => optional($v->starts_on)->toDateString(),
                'matures_on' => optional($v->matures_on)->toDateString(),
                'days_remaining' => $v->daysRemaining(),
                'status' => $v->status,
            ]),
        ]);
    }

    /**
     * Deposit from the wallet into a savings product.
     */
    public function deposit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product' => ['required', 'in:swiftsave,questsave,timevault'],
            'amount' => ['required', 'numeric', 'min:1'],
            'goal_id' => ['nullable', 'integer'],
            'term_days' => ['nullable', 'integer', 'in:30,90,180,365'],
            'pin' => ['nullable', 'string', 'digits:4,6'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;

        if ($member->hasBlockingHold()) {
            throw ValidationException::withMessages(['account' => ['Your account is suspended.']]);
        }

        $product = SavingsProduct::where('code', $request->input('product'))
            ->where('is_active', true)
            ->firstOrFail();

        $amount = (float) $request->input('amount');

        if ($amount < (float) $product->min_amount) {
            throw ValidationException::withMessages([
                'amount' => sprintf('The minimum deposit for %s is ₦%s.', $product->name, number_format((float) $product->min_amount, 2)),
            ]);
        }

        if ($product->max_amount !== null && $amount > (float) $product->max_amount) {
            throw ValidationException::withMessages([
                'amount' => sprintf('The maximum deposit for %s is ₦%s.', $product->name, number_format((float) $product->max_amount, 2)),
            ]);
        }

        $goal = null;
        if ($product->type === SavingsProduct::TYPE_GOAL && $request->filled('goal_id')) {
            $goal = $member->goals()->whereKey($request->input('goal_id'))->firstOrFail();
        }

        $targetType = $this->typeFor($product->type);

        $transaction = $this->walletService->allocate(
            $member,
            MemberAccount::TYPE_WALLET,
            $targetType,
            $amount,
            $goal,
        );

        return response()->json([
            'message' => sprintf('%s funded.', $product->name),
            'reference' => $transaction->reference,
            'balance' => round((float) $member->accounts()->where('type', $targetType)->value('balance'), 2),
        ], 201);
    }

    private function typeFor(string $productType): string
    {
        return match ($productType) {
            SavingsProduct::TYPE_FLEXIBLE => MemberAccount::TYPE_SWIFT,
            SavingsProduct::TYPE_GOAL => MemberAccount::TYPE_QUEST,
            SavingsProduct::TYPE_FIXED => MemberAccount::TYPE_VAULT,
            default => MemberAccount::TYPE_SWIFT,
        };
    }
}
