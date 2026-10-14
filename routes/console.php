<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Payment reconciliation.
 *
 * A webhook is a message, and messages get lost - a mistyped URL in the
 * provider dashboard, a minute of downtime, a retry budget exhausted. When
 * that happens the bank holds the member's money while the member sees an
 * empty wallet, and nothing in the system is aware anything went wrong.
 *
 * So the webhook stays the fast path and this is the net underneath it.
 * Every five minutes the ledger is compared against what the provider
 * actually received, and anything missing is posted. The run is idempotent
 * by reference, so it is safe for it to overlap a webhook that arrives
 * late, and safe for it to run repeatedly over the same window.
 */
Schedule::command('ourmonie:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/reconcile.log'));
