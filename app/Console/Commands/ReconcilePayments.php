<?php

namespace App\Console\Commands;

use App\Models\PaymentEvent;
use App\Models\Transaction;
use App\Services\Gateways\GatewayConfig;
use App\Services\Gateways\PaystackGateway;
use App\Services\PaymentEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find money the provider received but we never recorded.
 *
 * A webhook is a message, and messages get lost: the URL is mistyped in the
 * dashboard, the endpoint is unreachable for a minute, the provider retries
 * and gives up, TLS expires. When that happens the bank holds the member's
 * money and the member sees an empty wallet, with nothing in the system
 * noticing. On a savings product that is the difference between a delayed
 * credit and a member concluding they have been robbed.
 *
 * So the webhook is the fast path and this is the safety net. It asks the
 * provider what it actually received, and posts anything we have not
 * already booked.
 *
 * The listing is only ever used to discover that a reference exists. Every
 * amount and status is re-read through the verify endpoint inside
 * PaymentEngine before a kobo is posted, so nothing here trusts a summary
 * the provider handed over in a list. Credits are idempotent by reference
 * at the ledger, so running this twice - or running it alongside a webhook
 * that eventually arrives - cannot pay anyone twice.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'ourmonie:reconcile
        {--minutes=120 : How far back to look for unsettled transactions}
        {--dry-run : Report what would be credited without posting anything}';

    protected $description = 'Credit any payment the gateway received but our webhook never told us about';

    public function handle(): int
    {
        if (GatewayConfig::activeGateway() !== GatewayConfig::PAYSTACK) {
            $this->warn('Paystack is not the enabled gateway. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $minutes = max(5, (int) $this->option('minutes'));

        try {
            $rows = PaystackGateway::make()->listTransactions([
                'from' => now()->subMinutes($minutes)->getTimestamp(),
                'to' => now()->getTimestamp(),
            ]);
        } catch (\Throwable $exception) {
            // A reconciliation run that cannot reach the provider is not a
            // reason to alarm anyone yet, but it must not pass silently.
            $this->error('Could not reach Paystack: '.$exception->getMessage());
            Log::warning('Reconciliation could not reach the provider.', ['error' => $exception->getMessage()]);

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info(sprintf('Paystack reported no successful transactions in the last %d minutes.', $minutes));

            return self::SUCCESS;
        }

        $this->info(sprintf('Paystack reported %d successful transaction(s) in the last %d minutes.', count($rows), $minutes));

        $credited = 0;
        $alreadyBooked = 0;
        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($rows as $row) {
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
            $channel = (string) ($row['channel'] ?? 'unknown');

            if ($dryRun) {
                $this->line(sprintf(
                    '  would credit ₦%s from %s, reference %s',
                    number_format($amount, 2),
                    $channel,
                    $reference
                ));
                $credited++;

                continue;
            }

            // The list entry is wrapped as `data` so member resolution reads
            // exactly the fields the webhook would have supplied - the same
            // dedicated account number, the same customer email. One
            // resolution path, two ways of arriving at it.
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
                $failed++;
                $this->warn(sprintf('  could not settle %s: %s', $reference, $result['message']));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d credited, %d already booked, %d outstanding, %d failed.',
            $credited,
            $alreadyBooked,
            max(0, count($rows) - $credited - $alreadyBooked - $failed),
            $failed
        ));

        // A failure here means money is sitting at the provider that we
        // cannot account for. That deserves a non-zero exit so whatever is
        // watching the cron notices rather than the run quietly passing.
        if ($failed > 0) {
            Log::warning('Reconciliation left payments unsettled.', ['failed' => $failed]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
