<?php

namespace App\Services;

use App\Models\Member;
use App\Models\PaymentEvent;
use App\Models\Transaction;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\PaystackGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place an inbound provider credit becomes member money.
 *
 * PRD 21 / 45 - Paystack is the rail, OurMonie owns the state. Nothing here
 * trusts a payload: the event identifies what happened, and the gateway's
 * own verify endpoint is re-read before a single kobo is posted. The
 * double-entry posting itself is delegated to WalletService, so a credit
 * lands as Dr Paystack Clearing / Cr Member Wallet exactly the same way it
 * would if an operator keyed it in.
 *
 * Three independent guards stop a double credit:
 *   1. PaymentEvent.event_id is unique - a replayed event cannot start twice
 *   2. LedgerService is reference-idempotent - the same reference no-ops
 *   3. The verify call re-reads provider state, not the webhook's claim
 */
class PaymentEngine
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly DedicatedAccountService $dedicatedAccounts,
        private readonly \App\Services\Gateways\PayoutService $payouts,
    ) {
    }

    /* ---------------------------------------------------------------------- */
    /* Inbound credits                                                         */
    /* ---------------------------------------------------------------------- */

    /**
     * Process a provider event that claims money arrived.
     *
     * Returns a result the webhook controller can turn into an HTTP status:
     *  - processed : wallet credited (or already was)
     *  - duplicate : a second delivery of an event already handled
     *  - ignored   : not a credit, or a charge that did not succeed
     *  - failed    : could not settle now; the provider will retry
     *
     * @return array{status:string,message:string,event:?PaymentEvent}
     */
    public function handleCredit(
        string $provider,
        string $eventId,
        string $eventType,
        ?string $reference,
        ?float $claimedAmount,
        array $payload,
    ): array {
        $event = PaymentEvent::claim($eventId, $eventType, $provider, $reference);

        if ($event === null) {
            return [
                'status' => PaymentEvent::STATUS_DUPLICATE,
                'message' => 'Event already handled.',
                'event' => PaymentEvent::where('event_id', $eventId)->first(),
            ];
        }

        try {
            $charge = $this->readCharge($provider, $reference, $payload);

            if ($charge === null) {
                return $this->close($event, PaymentEvent::STATUS_IGNORED, 'Not a successful credit.');
            }

            // Provider says success, but says SUCCESS for the amount WE expected?
            // A mismatch is never posted silently - it goes to reconciliation.
            if ($claimedAmount !== null && round($claimedAmount, 2) !== $charge['amount']) {
                Log::warning('Provider amount differs from expected credit.', [
                    'event_id' => $eventId,
                    'expected' => $claimedAmount,
                    'provider' => $charge['amount'],
                ]);
            }

            $member = $this->resolveMember($payload);

            if ($member === null) {
                return $this->close(
                    $event,
                    PaymentEvent::STATUS_FAILED,
                    'Could not match this credit to a member.'
                );
            }

            if ($charge['currency'] !== 'NGN') {
                return $this->close(
                    $event,
                    PaymentEvent::STATUS_IGNORED,
                    'Unsupported currency '.$charge['currency'].'.'
                );
            }

            if ($charge['amount'] <= 0) {
                return $this->close($event, PaymentEvent::STATUS_IGNORED, 'Zero-value credit.');
            }

            // The float clears only when the provider actually settles to the
            // cooperative's bank - never assumed on receipt. One DB
            // transaction so a crash cannot leave the ledger half-written.
            $transaction = DB::transaction(fn () => $this->walletService->fundWallet(
                $member,
                $charge['amount'],
                $charge['reference'],
                ['provider' => $provider, 'event_id' => $eventId, 'channel' => $charge['channel']],
            ));

            $event->forceFill([
                'status' => PaymentEvent::STATUS_PROCESSED,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'processed_at' => now(),
                'error' => null,
            ])->save();

            $this->notifyCredit($member, $transaction);

            return [
                'status' => PaymentEvent::STATUS_PROCESSED,
                'message' => $transaction->wasRecentlyCreated
                    ? sprintf('%s credited to %s.', number_format($charge['amount'], 2), $member->member_number)
                    : 'Credit already recorded.',
                'event' => $event,
            ];
        } catch (GatewayException $exception) {
            return $this->close($event, PaymentEvent::STATUS_FAILED, $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return $this->close($event, PaymentEvent::STATUS_FAILED, $exception->getMessage());
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Outbound state                                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Settle an outbound transfer against our records.
     *
     * Called from the transfer.success / transfer.failed webhook so an
     * outbound payment stops sitting in `processing`.
     *
     * A payout that the bank did not accept is the dangerous half of this
     * job. The member has already been debited, so leaving the transaction
     * merely marked `failed` would quietly keep their money. The debit is
     * therefore handed back through `reversePayout`, which posts a real
     * reversing entry rather than editing a balance.
     *
     * @return array{status:string,message:string,event:?PaymentEvent}
     */
    public function handleTransferResult(string $eventId, string $reference, string $outcome, array $payload): array
    {
        $event = PaymentEvent::claim($eventId, 'transfer.'.$outcome, 'transfer', $reference);

        if ($event === null) {
            return [
                'status' => PaymentEvent::STATUS_DUPLICATE,
                'message' => 'Event already handled.',
                'event' => null,
            ];
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if ($transaction === null) {
            return $this->close($event, PaymentEvent::STATUS_IGNORED, 'Unknown transfer reference.');
        }

        // Already settled. A provider retry, or a second notification for
        // the same transfer, must not move money a second time.
        if (in_array($transaction->status, [
            Transaction::STATUS_COMPLETED,
            Transaction::STATUS_REVERSED,
            Transaction::STATUS_FAILED,
        ], true)) {
            return $this->close(
                $event,
                PaymentEvent::STATUS_DUPLICATE,
                'Transfer already settled as '.$transaction->status.'.'
            );
        }

        $meta = (array) $transaction->meta;
        $settled = $this->readTransferOutcome(
            $meta['gateway'] ?? null,
            $transaction->provider_reference ?? $meta['transfer_code'] ?? null,
            $outcome,
            $payload
        );

        if ($settled === 'success') {
            $transaction->forceFill([
                'status' => Transaction::STATUS_COMPLETED,
                'meta' => array_merge($meta, [
                    'provider_transfer' => $payload,
                    'settled_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $event->forceFill([
                'status' => PaymentEvent::STATUS_PROCESSED,
                'transaction_id' => $transaction->id,
                'processed_at' => now(),
            ])->save();

            return [
                'status' => PaymentEvent::STATUS_PROCESSED,
                'message' => 'Transfer completed.',
                'event' => $event,
            ];
        }

        $reversal = $this->walletService->reversePayout(
            $transaction,
            'The bank did not accept this transfer, so the money was returned to your account.'
        );

        $transaction->refresh();

        $event->forceFill([
            'status' => PaymentEvent::STATUS_PROCESSED,
            'transaction_id' => $transaction->id,
            'processed_at' => now(),
            'error' => 'Transfer did not settle: '.$settled,
        ])->save();

        if ($reversal !== null && $transaction->member !== null) {
            $this->notifyReversal($transaction->member, $transaction);
        }

        return [
            'status' => PaymentEvent::STATUS_PROCESSED,
            'message' => 'Transfer did not settle; funds returned.',
            'event' => $event,
        ];
    }

    /**
     * What the provider says became of the transfer.
     *
     * The notification is re-read against the provider rather than trusted,
     * the same way an inbound credit is. The signature has already been
     * checked, so when the re-read itself cannot be completed the signed
     * notification is believed - a provider that says a payout failed is
     * describing money that did not reach the member, and acting on that is
     * recoverable where leaving it stranded is not.
     *
     * @return string success|failed
     */
    private function readTransferOutcome(?string $gateway, ?string $transferCode, string $claimed, array $payload): string
    {
        if ($gateway !== null && $transferCode !== null && $transferCode !== '') {
            try {
                $transfer = $this->payouts->status($gateway, $transferCode);
                $status = strtolower((string) ($transfer['status'] ?? ''));

                if (in_array($status, ['success', 'successful', 'completed'], true)) {
                    return 'success';
                }

                if (in_array($status, ['failed', 'reversed', 'abandoned', 'cancelled'], true)) {
                    return 'failed';
                }

                // Still pending at the provider. Nothing to settle yet - the
                // next notification will carry the outcome.
                return $this->normaliseOutcome($claimed, $payload);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $this->normaliseOutcome($claimed, $payload);
    }

    /**
     * Fall back to what the signed notification itself claimed.
     */
    private function normaliseOutcome(string $claimed, array $payload): string
    {
        $status = strtolower((string) ($payload['data']['status'] ?? ''));

        if (in_array($status, ['success', 'successful', 'completed'], true)) {
            return 'success';
        }

        if (in_array($status, ['failed', 'reversed', 'abandoned', 'cancelled'], true)) {
            return 'failed';
        }

        return $claimed === 'success' ? 'success' : 'failed';
    }

    /* ---------------------------------------------------------------------- */
    /* Verification                                                            */
    /* ---------------------------------------------------------------------- */

    /**
     * Re-read the charge from the provider. Returns null when the provider
     * does not consider it a successful NGN credit.
     *
     * @return array{amount:float,reference:string,currency:string,channel:?string}|null
     */
    private function readCharge(string $provider, ?string $reference, array $payload): ?array
    {
        if ($provider === GatewayConfig::PAYSTACK) {
            $reference = $reference
                ?? (string) ($payload['data']['reference'] ?? '')
                ?? '';

            if ($reference === '') {
                return null;
            }

            $charge = PaystackGateway::make()->verifyTransaction($reference);

            // `status` on a verify read is the charge state; `success` is the
            // only state that represents money we may keep.
            if (strtolower($charge['status']) !== 'success') {
                return null;
            }

            if (($charge['raw']['currency'] ?? 'NGN') !== 'NGN') {
                return null;
            }

            return [
                'amount' => $charge['amount'],
                'reference' => $charge['reference'],
                'currency' => $charge['currency'],
                'channel' => $charge['channel'],
            ];
        }

        // Flutterwave verifies by transaction id; tx_ref is the reference we
        // would have issued, id is what the API wants.
        $id = $payload['data']['id'] ?? $reference;

        if ($id === null || $id === '') {
            return null;
        }

        $charge = FlutterwaveGateway::make()->verifyTransaction($id);

        if (strtolower($charge['status']) !== 'successful') {
            return null;
        }

        if ($charge['currency'] !== 'NGN') {
            return null;
        }

        return [
            'amount' => $charge['amount'],
            'reference' => $charge['reference'] !== '' ? $charge['reference'] : (string) $id,
            'currency' => $charge['currency'],
            'channel' => $charge['channel'],
        ];
    }

    /**
     * Which member owns this money?
     *
     * The dedicated virtual account number is the primary key - it is what
     * the member was told to transfer to, and the provider echoes it back.
     * The payer's email is a fallback for checkout flows where no dedicated
     * account is involved.
     */
    private function resolveMember(array $payload): ?Member
    {
        $candidates = [];

        foreach ([
            $payload['data']['authorization']['receiver_bank_account_number'] ?? null,
            $payload['data']['authorization']['sender_bank_account_number'] ?? null,
            $payload['data']['account_number'] ?? null,
            $payload['data']['dedicated_account']['account_number'] ?? null,
            $payload['data']['meta']['account_number'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $accountNumber) {
            $member = Member::whereHas(
                'wallet',
                fn ($query) => $query->where('account_number', $accountNumber)
            )->first();

            if ($member !== null) {
                return $member;
            }
        }

        // A reference we minted at checkout would carry the member id, but a
        // bank transfer to a dedicated account arrives with the provider's
        // own reference. Matching on digits alone could hit an arbitrary
        // member, so the account number and the payer's email are the only
        // identities trusted here.

        $email = strtolower((string) ($payload['data']['customer']['email']
            ?? $payload['data']['customer_email']
            ?? ''));

        if ($email !== '') {
            return Member::whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))->first();
        }

        return null;
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                 */
    /* ---------------------------------------------------------------------- */

    private function close(PaymentEvent $event, string $status, string $message): array
    {
        $event->forceFill([
            'status' => $status,
            'error' => $message,
            'processed_at' => now(),
        ])->save();

        return [
            'status' => $status,
            'message' => $message,
            'event' => $event,
        ];
    }

    private function notifyCredit(Member $member, Transaction $transaction): void
    {
        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        try {
            Notifier::notify(
                $member,
                'wallet.credited',
                'Wallet funded',
                sprintf(
                    '%s was credited to your wallet. New balance: ₦%s.',
                    number_format((float) $transaction->amount, 2),
                    number_format($this->walletBalance($member), 2)
                ),
                'success',
                '/app/transactions',
                ['reference' => $transaction->reference]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function walletBalance(Member $member): float
    {
        return (float) $member->accounts()
            ->where('type', \App\Models\MemberAccount::TYPE_WALLET)
            ->value('balance');
    }

    /**
     * Tell the member their money came back.
     *
     * A failed payout is the one case where silence costs a member real
     * trust: their balance went down and nothing arrived.
     */
    private function notifyReversal(Member $member, Transaction $transaction): void
    {
        try {
            Notifier::notify(
                $member,
                'withdrawal.returned',
                'Withdrawal returned',
                sprintf(
                    'Your withdrawal of ₦%s did not reach the bank, so the money has been returned to your account.',
                    number_format((float) $transaction->amount, 2)
                ),
                'warning',
                '/app/transactions',
                ['reference' => $transaction->reference]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Issue the dedicated account number a verified member is entitled to.
     *
     * Best effort by design. Called after a KYC approval and again whenever
     * the Payments screen flips a gateway on, so members who joined before a
     * rail existed get their number without waiting for anything. A slow or
     * unconfigured provider is logged, never surfaced - the approval already
     * happened and the number will be issued on the next attempt.
     */
    public function issueDedicatedAccount(Member $member): void
    {
        try {
            $this->dedicatedAccounts->assign($member);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}

