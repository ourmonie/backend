<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberAccount;
use App\Models\Transaction;
use App\Services\AccountProvisioner;
use App\Services\DedicatedAccountService;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\PaystackGateway;
use App\Services\Gateways\PayoutService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AccountProvisioner $provisioner,
        private readonly DedicatedAccountService $dedicatedAccounts,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $member = $request->user()->member->load(['accounts', 'wallet.account']);

        // PRD 87-91 - the number is issued by the gateway once a rail is live
        // and the member is verified. Best effort: a slow provider never
        // breaks this screen, it just means the member sees the same number
        // again next time and the assignment retries.
        $this->dedicatedAccounts->assign($member);
        $member->unsetRelation('wallet')->load('wallet');

        $wallet = $member->accounts->firstWhere('type', MemberAccount::TYPE_WALLET);
        $gateway = GatewayConfig::activeGateway();

        return response()->json([
            'account_number' => $member->wallet?->account_number,
            'account_name' => $member->wallet?->account_name ?? $member->user?->name,
            'bank_name' => $member->wallet?->bank_name ?? 'OurMonie',
            'provider' => $member->wallet?->provider,
            'account_status' => $member->wallet?->status ?? 'unassigned',
            'assignment_pending' => (bool) ($member->wallet?->meta['assignment_pending'] ?? false),
            'funding' => [
                'available' => $gateway !== null,
                'gateway' => $gateway,
                'method' => 'bank_transfer',
                'instruction' => $gateway === null
                    ? 'Bank transfer is not configured yet. Contact support to fund your wallet.'
                    : 'Transfer to the account number above. Your wallet is credited once the transfer lands.',
            ],
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
     * Confirm a bank transfer the member says they have sent.
     *
     * PRD 21/45 - this endpoint NEVER credits anyone. It reads provider state
     * and returns what the provider says. Money only ever enters the ledger
     * through PaymentEngine, from a signed webhook that was re-verified
     * against the provider's own record before a kobo moved.
     *
     * It exists for one reason: a webhook can be slow, and a member staring
     * at a wallet that has not moved yet needs an answer. Re-asking Paystack
     * to look for the transfer (requery) is the supported way to hurry it.
     */
    public function fundingStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $this->guard($member, $request, 'funding_restriction');

        $reference = trim($request->input('reference'));

        $recorded = Transaction::where('member_id', $member->id)
            ->where('type', Transaction::WALLET_FUNDING)
            ->where(function ($query) use ($reference) {
                $query->where('reference', $reference)
                    ->orWhere('provider_reference', $reference);
            })
            ->first();

        if ($recorded !== null && $recorded->status === Transaction::STATUS_COMPLETED) {
            return response()->json([
                'settled' => true,
                'message' => sprintf(
                    '%s is already in your wallet.',
                    number_format((float) $recorded->amount, 2)
                ),
                'reference' => $recorded->reference,
                'amount' => round((float) $recorded->amount, 2),
                'balance' => $this->balanceOf($member, MemberAccount::TYPE_WALLET),
            ]);
        }

        // Nothing recorded yet - ask the provider to look for it, and let the
        // signed webhook do the crediting when it finds one.
        $requery = $this->requeryProvider($member);

        return response()->json([
            'settled' => false,
            'message' => $requery['message'],
            'balance' => $this->balanceOf($member, MemberAccount::TYPE_WALLET),
            'checked_at' => now()->toIso8601String(),
        ], 202);
    }

    /**
     * Ask the active gateway to re-scan the member's dedicated account for
     * transfers it has not told us about yet. Returns false when no gateway
     * is configured - the screen still works, it just has nothing to ask.
     */
    private function requeryProvider($member): array
    {
        $wallet = $member->wallet()->first();

        if ($wallet === null || $wallet->provider_reference === null) {
            return ['message' => 'We have not received a transfer on this account yet. It can take a few minutes.'];
        }

        $gateway = GatewayConfig::activeGateway();

        if ($gateway !== GatewayConfig::PAYSTACK) {
            return ['message' => 'We have not received a transfer on this account yet. It can take a few minutes.'];
        }

        try {
            \App\Services\Gateways\PaystackGateway::make()
                ->requeryDedicatedAccount($wallet->account_number);

            return ['message' => 'We asked the bank to re-check. Any transfer on this account will be credited shortly.'];
        } catch (\Throwable $exception) {
            report($exception);

            return ['message' => 'We have not received a transfer on this account yet. It can take a few minutes.'];
        }
    }

    private function balanceOf($member, string $type): float
    {
        return round((float) $member->accounts()->where('type', $type)->value('balance'), 2);
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
     * The banks a withdrawal can be sent to.
     *
     * Served from whichever gateway is active so the member picks from the
     * provider's own directory rather than typing a name. That is what gives
     * the withdrawal a bank code, which is what makes verifying the
     * destination account possible in the first place - a free-text bank
     * name carries nothing a provider can resolve against.
     */
    public function banks(): JsonResponse
    {
        $gateway = GatewayConfig::activeGateway();

        if ($gateway === null) {
            return response()->json(['banks' => []]);
        }

        $banks = Cache::remember('payout.banks.'.$gateway, now()->addDay(), function () use ($gateway) {
            $rows = $gateway === GatewayConfig::FLUTTERWAVE
                ? app(FlutterwaveGateway::class)->banks()
                : app(PaystackGateway::class)->banks();

            return collect($rows)
                ->map(fn (array $bank) => [
                    'code' => (string) ($bank['code'] ?? ''),
                    'name' => (string) ($bank['name'] ?? ''),
                ])
                ->filter(fn (array $bank) => $bank['code'] !== '' && $bank['name'] !== '')
                ->unique('code')
                ->sortBy('name')
                ->values()
                ->all();
        });

        return response()->json(['banks' => $banks]);
    }

    /**
     * Withdraw to a Nigerian bank account, applying the configured fee.
     *
     * Money only leaves by a real provider transfer. The order of operations
     * is what makes it safe to fail at any point:
     *
     *   1. The destination is name-enumerated against the bank BEFORE the
     *      member is debited, so a mistyped account number costs nothing.
     *   2. The member is debited as `processing`, not `completed`. Their
     *      money is held down so it cannot be spent again while the transfer
     *      is in flight, but nothing here claims the payout succeeded.
     *   3. The provider transfer is initiated against that same reference.
     *   4. If initiation fails, the debit is reversed before the member is
     *      told anything.
     *
     * Completion is not this endpoint's business. The provider confirms it
     * by webhook, and until then the withdrawal is honestly shown as
     * processing.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_number' => ['required', 'string', 'digits:10'],
            'bank_code' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:100', 'max:5000000'],
            'source' => ['nullable', 'in:wallet,swift_save,quest_save,time_vault'],
            'pin' => ['required', 'string', 'digits:4,6'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $member = $request->user()->member;
        $this->guard($member, $request, 'withdrawal_restriction');

        // Nothing is debited until a rail is actually available to carry the
        // money. Taking a balance and then discovering there is no gateway
        // would strand the member.
        $gateway = GatewayConfig::activeGateway();

        if ($gateway === null) {
            throw ValidationException::withMessages([
                'amount' => ['Withdrawals are temporarily unavailable. Please try again shortly.'],
            ]);
        }

        $this->verifyPin($request);

        $source = $request->input('source', MemberAccount::TYPE_WALLET);
        $account = $this->provisioner->accountFor($member, $source);

        $amount = round((float) $request->input('amount'), 2);
        $fee = $this->resolveFee($member, $amount);
        $total = round($amount + $fee, 2);

        if (! $account->isAvailable()) {
            throw ValidationException::withMessages(['source' => ['This account is not available for withdrawals.']]);
        }

        // The balance and the spendable balance are not the same figure for
        // a TimeVault. Money locked until maturity cannot be withdrawn early.
        $available = $this->walletService->availableBalance($member, $source);
        $locked = round((float) $account->balance - $available, 2);

        if ($available < $total) {
            throw ValidationException::withMessages([
                'amount' => $locked > 0
                    ? sprintf(
                        '₦%s of this account is locked in a TimeVault that has not matured. ₦%s is available to withdraw.',
                        number_format($locked, 2),
                        number_format($available, 2)
                    )
                    : sprintf(
                        'Insufficient balance. You have ₦%s available and ₦%s will be deducted including fees.',
                        number_format($available, 2),
                        number_format($total, 2)
                    ),
            ]);
        }

        try {
            // Confirms the account number resolves to the name given, at the
            // bank. The single check that stops a withdrawal reaching a
            // stranger because a digit was mistyped.
            $destination = app(PayoutService::class)->resolve(
                $gateway,
                $request->input('account_number'),
                $request->input('bank_code'),
                $request->input('account_name'),
            );
        } catch (GatewayException $exception) {
            throw ValidationException::withMessages(['account_number' => $exception->getMessage()]);
        }

        $reference = Transaction::generateReference('WD');

        $transaction = $this->walletService->withdraw(
            $member,
            $source,
            $amount,
            $fee,
            trim($destination['name'].' - '.$request->input('bank_name')),
            null,
            'Withdrawal to bank account',
            Transaction::STATUS_PROCESSING,
            $reference,
        );

        try {
            $transfer = app(PayoutService::class)->send(
                $gateway,
                $destination,
                $amount,
                $reference,
                'OurMonie withdrawal '.$reference,
            );
        } catch (GatewayException $exception) {
            // The provider refused, so the debit never represented money
            // that left. Hand it back now rather than leaving the member
            // short while someone notices.
            $this->walletService->reversePayout($transaction, 'Provider declined the transfer: '.$exception->getMessage());

            throw ValidationException::withMessages([
                'amount' => 'Your withdrawal could not be sent and your balance has been restored. '.$exception->getMessage(),
            ]);
        }

        $transaction->forceFill([
            'provider_reference' => $transfer['transfer_code'],
            'meta' => array_merge((array) $transaction->meta, [
                'gateway' => $transfer['provider'],
                'transfer_code' => $transfer['transfer_code'],
                'gateway_status' => $transfer['status'],
                'destination' => [
                    'account_number' => $destination['account_number'],
                    'account_name' => $destination['name'],
                    'bank_name' => $destination['bank_name'],
                ],
            ]),
        ])->save();

        return response()->json([
            'message' => 'Withdrawal submitted and being processed.',
            'reference' => $transaction->reference,
            'amount' => $amount,
            'fee' => $fee,
            'status' => $transaction->status,
            'balance' => round((float) $member->accounts()->where('type', $source)->value('balance'), 2),
        ], 202);
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
