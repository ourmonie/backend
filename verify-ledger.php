<?php

use App\Models\LedgerEntry;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\Transaction;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'Transactions : '.Transaction::count().PHP_EOL;
echo 'Ledger rows  : '.LedgerEntry::count().PHP_EOL;

$debits = (float) LedgerEntry::where('direction', 'debit')->sum('amount');
$credits = (float) LedgerEntry::where('direction', 'credit')->sum('amount');
printf("Total debits : %s\n", number_format($debits, 2));
printf("Total credits: %s\n", number_format($credits, 2));
printf("DIFF         : %s\n\n", number_format($debits - $credits, 2));

$allOk = true;
foreach (MemberAccount::all() as $account) {
    $credit = (float) LedgerEntry::where('member_account_id', $account->id)->where('direction', 'credit')->sum('amount');
    $debit = (float) LedgerEntry::where('member_account_id', $account->id)->where('direction', 'debit')->sum('amount');
    $derived = round($credit - $debit, 2);
    $stored = round((float) $account->balance, 2);
    $ok = abs($derived - $stored) < 0.005;
    $allOk = $allOk && $ok;
    printf(
        "%-12s stored=%12s  derived=%12s  %s\n",
        $account->type,
        number_format($stored, 2),
        number_format($derived, 2),
        $ok ? 'OK' : '*** MISMATCH ***'
    );
}

echo PHP_EOL.'Chart of accounts balance check:'.PHP_EOL;
$accountTotals = [];
foreach (LedgerEntry::query()->selectRaw('ledger_account_id, direction, SUM(amount) as total')->groupBy('ledger_account_id', 'direction')->get() as $row) {
    $accountTotals[$row->ledger_account_id][$row->direction] = (float) $row->total;
}
foreach (\App\Models\LedgerAccount::all() as $ledger) {
    $sides = $accountTotals[$ledger->id] ?? [];
    $d = $sides['debit'] ?? 0.0;
    $c = $sides['credit'] ?? 0.0;
    $balance = $ledger->normal_side === 'debit' ? $d - $c : $c - $d;
    if (abs($balance) < 0.005) {
        continue;
    }
    printf("  %-8s %-34s %14s\n", $ledger->code, $ledger->name, number_format($balance, 2));
}

$member = Member::first();
echo PHP_EOL.'Member: '.$member->member_number.' / '.$member->user->name.PHP_EOL;
echo 'Wallet account no: '.($member->wallet?->account_number ?? 'n/a').PHP_EOL;
echo 'Wallet balance    : '.number_format((float) $member->wallet?->account?->balance, 2).PHP_EOL;
echo 'Reward points     : '.number_format((float) $member->rewardAccount?->points_balance).PHP_EOL;
echo PHP_EOL.($allOk ? 'ALL MEMBER ACCOUNTS RECONCILE' : 'RECONCILIATION FAILED').PHP_EOL;
