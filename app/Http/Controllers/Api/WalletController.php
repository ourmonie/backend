<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberAccount;
use App\Models\Transaction;
use App\Services\AccountProvisioner;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AccountProvisioner $provisioner,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $member = $request->user()->member->load(['accounts', 'wallet.account']);

        $wallet = $member->accounts->firstWhere('type', MemberAccount::TYPE_WALLET);

        return response()->json([
            'account_number' => $member->wallet?->account_number,
            'account_name' => $member->wallet?->account_name ?? $member->user?->name,
            'bank_name' => $member->wallet?->bank_name ?? 'OurMonie',
            'provider' => $member->wallet?->provider,
            'balance' => round((float) $wallet?->balance, 2),
            'currency' => 'NGN',
            'status' => $wallet?->status,
            'holds' => $member->activeHolds()->get()->map(fn ($h) => [
                'type' => $h->hold_type,
                'reason' => $h->reason,
                'expires_at' => optional($h->expires_at)->toDateTimeString(),
            ]),
            'accounts' => $member->accounts->map(fn ($a) => [
                'type' => $a->type,
                'label' => $a->label(),
                'account_number' => $a->account_number,
                'balance' => round((float) $a->balance, 2),
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Record an inbound transfer to the member's dedicated account number.
     *
     * In production this arrives as a Paystack transfer-success webhook. The
     * `reference` is supplied by the provider, so LedgerService treats a
     * replay of the same event as a no-op and the wallet can never be
     * credited twice for one real transfer.
     */
    public function fund(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:100'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $this->guard($member, $request, 'funding_restriction');

        $amount = round((float) $request->input('amount'), 2);
        $reference = $request->input('reference') ?: 'DVA'.strtoupper(bin2hex(random_bytes(5)));

        $limit = \App\Models\TransactionLimit::resolve(
            'wallet_funding',
            (int) $member->kyc_level,
            $member->membership_tier_id,
        );

        if ($limit?->daily_limit !== null) {
            $usedToday = (float) Transaction::where('member_id', $member->id)
                ->where('type', Transaction::WALLET_FUNDING)
                ->where('status', '!=', Transaction::STATUS_FAILED)
                ->where('occurred_at', '>=', now()->startOfDay())
                ->sum('amount');

            if ($usedToday + $amount > (float) $limit->daily_limit) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'This would exceed your daily wallet funding limit of ₦%s.',
                        number_format((float) $limit->daily_limit, 2)
                    ),
                ]);
            }
        }

        $before = round((float) $member->accounts()
            ->where('type', MemberAccount::TYPE_WALLET)->value('balance'), 2);

        // The credit and the provider settlement are one atomic step, so a
        // failure can never leave the float half-cleared. Both postings are
        // reference-idempotent, so a retry completes or no-ops safely.
        [$transaction, $wallet] = DB::transaction(fn () => [
            $this->walletService->fundWallet($member, $amount, $reference),
            $this->walletService->settleFromProvider($amount, $reference),
        ]);

        return response()->json([
            'message' => $transaction->wasRecentlyCreated
                ? sprintf('%s credited to your wallet.', number_format($amount, 2))
                : 'This transfer was already recorded.',
            'reference' => $transaction->reference,
            'amount' => $amount,
            'balance_before' => $before,
            'balance' => round((float) $member->accounts()
                ->where('type', MemberAccount::TYPE_WALLET)->value('balance'), 2),
            'settled_reference' => $wallet->reference,
        ], $transaction->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Move the member's own money between eligible products.
     */
    public function allocate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'in:wallet,swift_save,quest_save,time_vault'],
            'to' => ['required', 'in:wallet,swift_save,quest_save,time_vault'],
            'amount' => ['required', 'numeric', 'min:1'],
            'goal_id' => ['nullable', 'integer', 'exists:savings_goals,id'],
            'pin' => ['nullable', 'string', 'digits:4,6'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $this->guard($member, $request, 'transaction_restriction');

        if ($request->input('from') === $request->input('to')) {
            throw ValidationException::withMessages(['to' => ['Source and destination must differ.']]);
        }

        $goal = null;
        if ($request->filled('goal_id')) {
            $goal = $member->goals()->whereKey($request->input('goal_id'))->firstOrFail();
        }

        $transaction = $this->walletService->allocate(
            $member,
            $request->input('from'),
            $request->input('to'),
            (float) $request->input('amount'),
            $goal,
        );

        return response()->json([
            'message' => 'Funds allocated successfully.',
            'reference' => $transaction->reference,
            'balance' => round((float) $member->accounts()->where('type', $request->input('from'))->value('balance'), 2),
        ], 201);
    }

    /**
     * Withdraw to a Nigerian bank account, applying the configured fee.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_number' => ['required', 'string', 'digits:10'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:100'],
            'source' => ['nullable', 'in:wallet,swift_save,quest_save,time_vault'],
            'pin' => ['required', 'string', 'digits:4,6'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $this->guard($member, $request, 'withdrawal_restriction');

        $this->verifyPin($request);

        $source = $request->input('source', MemberAccount::TYPE_WALLET);
        $account = $this->provisioner->accountFor($member, $source);

        $fee = $this->resolveFee($member, (float) $request->input('amount'));
        $total = (float) $request->input('amount') + $fee;

        if ((float) $account->balance < $total) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Insufficient balance. You have ₦%s available and ₦%s will be deducted including fees.',
                    number_format((float) $account->balance, 2),
                    number_format($total, 2)
                ),
            ]);
        }

        if (! $account->isAvailable()) {
            throw ValidationException::withMessages(['source' => ['This account is not available for withdrawals.']]);
        }

        $transaction = $this->walletService->withdraw(
            $member,
            $source,
            (float) $request->input('amount'),
            $fee,
            trim($request->input('account_name').' - '.$request->input('bank_name')),
        );

        return response()->json([
            'message' => 'Withdrawal submitted.',
            'reference' => $transaction->reference,
            'amount' => (float) $request->input('amount'),
            'fee' => $fee,
            'balance' => round((float) $member->accounts()->where('type', $source)->value('balance'), 2),
        ], 201);
    }

    /* ------------------------------- helpers -------------------------------- */

    private function guard($member, Request $request, string $holdType): void
    {
        if ($member->hasBlockingHold()) {
            throw ValidationException::withMessages([
                'account' => ['Your account is suspended. Please contact support.'],
            ]);
        }

        $active = $member->activeHolds()->where('hold_type', $holdType)->exists();
        if ($active) {
            throw ValidationException::withMessages([
                'account' => ['This action is restricted on your account. Please contact support.'],
            ]);
        }
    }

    private function verifyPin(Request $request): void
    {
        $user = $request->user();

        if ($user->transaction_pin === null) {
            throw ValidationException::withMessages([
                'pin' => ['Set a transaction PIN in Settings before making withdrawals.'],
            ]);
        }

        if (! \Illuminate\Support\Facades\Hash::check($request->input('pin'), $user->transaction_pin)) {
            throw ValidationException::withMessages(['pin' => ['Incorrect transaction PIN.']]);
        }
    }

    /**
     * Withdrawal fee comes from configuration, never a hardcoded figure.
     */
    private function resolveFee($member, float $amount): float
    {
        $fee = \App\Models\FeeConfig::byCode('withdrawal_flat');

        if ($fee === null || $member->tier?->code === 'platinum') {
            return $fee?->bearer_member ? 0.0 : 0.0;
        }

        return $fee->calculate($amount);
    }
}
