<?php

namespace App\Console\Commands;

use App\Models\PaymentEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\PayoutService;
use App\Services\Gateways\PaystackGateway;
use App\Services\PaymentEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Find money the provider received but we never recorded, and settle
 * payouts the provider decided without telling us.
 *
 * A webhook is a message, and messages get lost: the URL is mistyped in the
 * dashboard, the endpoint is unreachable for a minute, the provider retries
 * and gives up. When that happens on an inbound credit the bank holds the
 * member's money while the member sees an empty wallet, and nothing in the
 * system notices. On an outbound payout it is worse - the money has already
 * left the member's balance, so a transfer that settles unheard leaves the
 * withdrawal sitting in `processing` for as long as the member keeps using
 * the product.
 *
 * So the webhook is the fast path and this is the net underneath it. Both
 * directions are covered, because a net that only catches money coming in
 * still lets money going out go missing.
 *
 * This account is shared with other products. The listing is therefore
 * filtered to transactions that can be shown to belong to OurMonie, and
 * pages deep enough that traffic on a sibling product cannot push our own
 * transactions out of reach. A transaction that belongs to somebody else is
 * not a failure and is never reported as one - it is simply not ours to
 * book, and treating it as an error is how an operator learns to ignore the
 * output at the exact moment it starts saying something important.
 *
 * Listings are only ever used to discover that a reference exists. Every
 * amount and status is re-read through the verify endpoint inside
 * PaymentEngine before a kobo is posted, so nothing here trusts a summary
 * the provider handed over in a list. Credits are idempotent by reference
 * at the ledger, so running this twice - or running it alongside a webhook
 * that eventually arrives - cannot pay anyone twice.
 */
class ReconcilePayments extends Command
{
    /** Pages of 100 pulled per run. */
    private const MAX_PAGES = 10;

    protected $signature = 'ourmonie:reconcile
        {--minutes=120 : How far back to look for unsettled transactions}
        {--dry-run : Report what would be credited without posting anything}';

    protected $description = 'Credit payments the gateway received but our webhook never told us about, and settle payouts it decided on its own';

    public function handle(): int
    {
        if (GatewayConfig::activeGateway() !== GatewayConfig::PAYSTACK) {
            $this->warn('Paystack is not the enabled gateway. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $gateway = PaystackGateway::make();
        $minutes = max(5, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');

        // Say which account is about to be read before reporting anything
        // about it. "No transactions found" is either an honest all-clear or
        // a sign this run is pointed at the wrong key, and the two are
        // indistinguishable unless the mode is on screen.
        try {
            $connection = $gateway->checkConnection();
        } catch (\Throwable $exception) {
            $this->error('Could not authenticate with Paystack: '.$exception->getMessage());
            Log::warning('Reconciliation could not authenticate.', ['error' => $exception->getMessage()]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reading Paystack in %s mode. Settlement balance: %s %s.',
            strtoupper((string) $connection['mode']),
            $connection['currency'] ?? 'NGN',
            $connection['balance'] ?? 'unknown'
        ));

        [$credited, $alreadyBooked, $outstanding, $unreadable] = $this->reconcileInbound(
            $gateway,
            $minutes,
            $dryRun
        );

        $this->newLine();

        [$completed, $returned, $stillOpen] = $this->reconcileOutbound(
            app(PaymentEngine::class),
            app(PayoutService::class),
            $dryRun
        );

        $this->newLine();
        $this->info(sprintf(
            'Inbound: %d credited, %d already booked, %d still outstanding, %d could not be read.',
            $credited,
            $alreadyBooked,
            $outstanding,
            $unreadable
        ));
        $this->info(sprintf(
            'Outbound: %d completed, %d returned to the member, %d still awaiting the bank.',
            $completed,
            $returned,
            $stillOpen
        ));

        // Anything left outstanding is money at the provider that we cannot
        // account for. That deserves a non-zero exit so whatever is watching
        // the cron notices rather than the run quietly passing.
        if ($outstanding > 0 || $unreadable > 0) {
            Log::warning('Reconciliation left payments unsettled.', [
                'outstanding' => $outstanding,
                'unreadable' => $unreadable,
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------------- */
    /* Inbound */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array{0:int,1:int,2:int,3:int} credited, already booked, outstanding, unreadable
     */
    private function reconcileInbound(PaystackGateway $gateway, int $minutes, bool $dryRun): array
    {
        $rows = $this->transactionsInWindow($gateway, $minutes);
        $mine = array_values(array_filter($rows, fn (array $row) => $this->belongsToOneOfOurs($row)));

        $this->info(sprintf(
            'Inbound: Paystack reported %d transaction(s) in the last %d minutes; %d belong to OurMonie.',
            count($rows),
            $minutes,
            count($mine)
        ));

        $credited = 0;
        $alreadyBooked = 0;
        $outstanding = 0;
        $unreadable = 0;

        foreach ($mine as $row) {
            $reference = (string) ($row['reference'] ?? '');

            if ($reference === '') {
                continue;
            }

            // Anything already in our books is settled. Checked against the
            // ledger's own reference rather than the webhook log, because a
            // webhook may have been recorded and still failed to post.
            if (Transaction::where('reference', $reference)->exists()) {
                $alreadyBooked++;

                continue;
            }

            $amount = round(((float) ($row['amount'] ?? 0)) / 100, 2);

            if ($dryRun) {
                $this->line(sprintf('  would credit ₦%s, reference %s', number_format($amount, 2), $reference));
                $credited++;

                continue;
            }

            // Wrapped as `data` so member resolution reads exactly the
            // fields the webhook would have supplied. One resolution path,
            // two ways of arriving at it.
            $result = app(PaymentEngine::class)->handleCredit(
                GatewayConfig::PAYSTACK,
                'reconcile:'.$reference,
                'charge.reconciled',
                $reference,
                $amount,
                ['data' => $row]
            );

            if ($result['status'] === PaymentEvent::STATUS_PROCESSED) {
                $credited++;
                $this->line(sprintf('  credited ₦%s, reference %s', number_format($amount, 2), $reference));
            } elseif ($result['status'] === PaymentEvent::STATUS_IGNORED) {
                $this->line(sprintf('  skipped %s: %s', $reference, $result['message']));
            } else {
                $unreadable++;
                $this->warn(sprintf('  could not settle %s: %s', $reference, $result['message']));
            }
        }

        // Not ours, and not a problem. Counted only so the summary explains
        // the gap between what the account did and what we looked at.
        $outstanding = max(0, count($mine) - $credited - $alreadyBooked - $unreadable);

        return [$credited, $alreadyBooked, $outstanding, $unreadable];
    }

    /**
     * Every successful transaction in the window, newest first.
     *
     * Pages deep rather than taking one page, because this account carries
     * other products' traffic. A single page of 100 would be consumed by a
     * busy voting round, and our own members' payments would quietly fall
     * off the end while the run still reported success.
     *
     * @return array<int, array>
     */
    private function transactionsInWindow(PaystackGateway $gateway, int $minutes): array
    {
        $parameters = [
            'from' => now()->subMinutes($minutes)->getTimestamp(),
            'to' => now()->getTimestamp(),
        ];

        $rows = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $gateway->listTransactions(array_merge($parameters, ['page' => $page]));

            $rows = array_merge($rows, $batch);

            if (count($batch) < 100) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Can this transaction be shown to belong to a member of ours?
     *
     * Two ways in, matching the two ways money arrives. A transfer to a
     * dedicated account we issued names that account number; anything taken
     * through a hosted page or a card charge carries the customer's email.
     * A transaction that satisfies neither is somebody else's, and the
     * correct response is to leave it alone rather than to try it and report
     * a failure.
     */
    private function belongsToOneOfOurs(array $row): bool
    {
        $account = (string) ($row['authorization']['receiver_bank_account_number'] ?? '')
            ?: (string) ($row['dedicated_account']['account_number'] ?? '')
            ?: (string) ($row['account_number'] ?? '');

        if ($account !== '' && in_array($account, $this->issuedAccountNumbers(), true)) {
            return true;
        }

        $email = strtolower(trim((string) ($row['customer']['email'] ?? '')));

        return $email !== '' && in_array($email, $this->memberEmails(), true);
    }

    /** @return array<int, string> */
    private function issuedAccountNumbers(): array
    {
        static $numbers = null;

        if ($numbers === null) {
            $numbers = Wallet::query()
                ->whereNotNull('account_number')
                ->pluck('account_number')
                ->map(fn ($value) => (string) $value)
                ->all();
        }

        return $numbers;
    }

    /** @return array<int, string> */
    private function memberEmails(): array
    {
        static $emails = null;

        if ($emails === null) {
            $emails = User::query()
                ->pluck('email')
                ->map(fn ($value) => strtolower(trim((string) $value)))
                ->all();
        }

        return $emails;
    }

    /* ---------------------------------------------------------------------- */
    /* Outbound */
    /* ---------------------------------------------------------------------- */

    /**
     * Settle payouts the provider has already decided.
     *
     * This is the half a webhook-less deployment cannot do without. A
     * withdrawal debits the member immediately and is only marked complete
     * when the bank accepts the transfer; without something to report that
     * back, the withdrawal sits in `processing` forever with the money
     * already out of the member's balance.
     *
     * Only a definitive answer is acted on. A transfer still pending at the
     * provider, or one that cannot be read at all, is left exactly as it is
     * and picked up on the next run - settling on anything less could mark a
     * payout complete that never went out, and a completed transaction
     * refuses the reversal that would hand the money back.
     *
     * @return array{0:int,1:int,2:int} completed, returned, still open
     */
    private function reconcileOutbound(PaymentEngine $engine, PayoutService $payouts, bool $dryRun): array
    {
        $pending = Transaction::query()
            ->where('status', Transaction::STATUS_PROCESSING)
            ->whereNotNull('provider_reference')
            ->orderBy('id')
            ->get();

        $this->info(sprintf('Outbound: %d payout(s) still awaiting a bank decision.', $pending->count()));

        $completed = 0;
        $returned = 0;
        $stillOpen = 0;

        foreach ($pending as $transaction) {
            $meta = (array) $transaction->meta;
            $gateway = (string) ($meta['gateway'] ?? '');
            $transferCode = (string) ($transaction->provider_reference ?? '');

            if ($gateway === '' || $transferCode === '') {
                $stillOpen++;

                continue;
            }

            try {
                $transfer = $payouts->status($gateway, $transferCode);
            } catch (\Throwable $exception) {
                // An unreadable transfer is not a failed one. It stays in
                // processing and is asked about again next run.
                report($exception);
                $stillOpen++;

                continue;
            }

            $status = strtolower((string) ($transfer['status'] ?? ''));

            if (in_array($status, ['success', 'successful', 'completed'], true)) {
                $outcome = 'success';
            } elseif (in_array($status, ['failed', 'reversed', 'abandoned', 'cancelled'], true)) {
                $outcome = 'failed';
            } else {
                $stillOpen++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  would mark %s as %s, reference %s',
                    $transaction->reference,
                    $outcome,
                    $transferCode
                ));

                if ($outcome === 'success') {
                    $completed++;
                } else {
                    $returned++;
                }

                continue;
            }

            // The payload carries the status just read, so that if the
            // engine's own re-read of the provider fails mid-call, the
            // fallback it falls back to is a real answer rather than a
            // guess. The engine re-reads independently either way.
            $result = $engine->handleTransferResult(
                'reconcile:transfer:'.$transaction->reference,
                $transaction->reference,
                $outcome,
                ['data' => ['status' => $status]]
            );

            if ($result['status'] === PaymentEvent::STATUS_PROCESSED) {
                if ($outcome === 'success') {
                    $completed++;
                    $this->line(sprintf('  completed payout %s', $transaction->reference));
                } else {
                    $returned++;
                    $this->line(sprintf('  returned payout %s to the member', $transaction->reference));
                }
            } elseif ($result['status'] === PaymentEvent::STATUS_IGNORED) {
                // The provider had not decided when the engine re-read it.
                $stillOpen++;
            } else {
                $stillOpen++;
            }
        }

        return [$completed, $returned, $stillOpen];
    }
}
