<?php

/**
 * Dev helper: prints the derived cash value for representative point balances
 * so the conversion tiers can be checked without touching the UI.
 *
 *   php check-rewards.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RewardConversionTier;

echo "Active conversion tiers:\n";
foreach (RewardConversionTier::orderBy('min_points')->get() as $tier) {
    $rate = $tier->cash_value / max(1, $tier->min_points);
    printf(
        "  %-8s %s - %s pts -> %.2f  (rate %.4f / point)\n",
        $tier->label,
        number_format($tier->min_points),
        $tier->max_points === null ? 'no cap' : number_format($tier->max_points),
        $tier->cash_value,
        $rate,
    );
}

echo "\nDerived value of a balance:\n";
foreach ([0, 1, 5000, 9999, 10000, 10001, 30000, 50000, 94200, 100000, 150000] as $points) {
    printf(
        "  %7s pts -> %10s   (rate %.4f / point)\n",
        number_format($points),
        '₦'.number_format(RewardConversionTier::cashValueFor($points), 2),
        $points > 0 ? RewardConversionTier::cashValueFor($points) / $points : 0,
    );
}

$demo = RewardConversionTier::cashValueFor(94200);
printf("\nDemo headline (94,200 pts): ₦%s  %s\n", number_format($demo, 2), $demo === 8420.0 ? 'OK' : 'MISMATCH');
printf("Threshold conversion (10,000 pts): ₦%s  %s\n",
    number_format(RewardConversionTier::cashValueFor(10000), 2),
    RewardConversionTier::cashValueFor(10000) > 0 ? 'OK' : 'DEAD ZONE'
);
