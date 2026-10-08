<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardAccount extends Model
{
    protected $fillable = [
        'member_id', 'points_balance', 'lifetime_earned',
        'lifetime_redeemed', 'pending_points',
    ];

    protected function casts(): array
    {
        return [
            'points_balance' => 'integer',
            'lifetime_earned' => 'integer',
            'lifetime_redeemed' => 'integer',
            'pending_points' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RewardEntry::class);
    }

    /**
     * Cash value of the current balance using admin-configured tiers.
     * The conversion formula is never hardcoded - it lives in reward tiers.
     */
    public function cashValue(): float
    {
        return RewardConversionTier::cashValueFor((int) $this->points_balance);
    }

    /**
     * Minimum points before any conversion becomes possible.
     */
    public function lowestConversionThreshold(): int
    {
        return RewardConversionTier::threshold();
    }

    /**
     * Whether the balance has reached the admin-defined conversion threshold.
     */
    public function isConvertible(): bool
    {
        $threshold = $this->lowestConversionThreshold();

        return $threshold > 0 && (int) $this->points_balance >= $threshold;
    }

    /**
     * Cash value a given historic point balance would have carried.
     * Used for month-over-month reward growth reporting.
     */
    public static function cashValueAt(int $points): float
    {
        return RewardConversionTier::cashValueFor($points);
    }
}
