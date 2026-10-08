<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsProduct extends Model
{
    public const TYPE_FLEXIBLE = 'flexible';   // SwiftSave
    public const TYPE_GOAL = 'goal';           // QuestSave
    public const TYPE_FIXED = 'fixed';         // TimeVault

    protected $fillable = [
        'code', 'name', 'type', 'description', 'icon',
        'interest_rate', 'interest_period', 'interest_payout',
        'min_amount', 'max_amount', 'min_balance',
        'allowed_terms', 'early_withdrawal_allowed', 'penalty_type',
        'penalty_value', 'maturity_behaviour', 'contribution_frequency',
        'min_contribution', 'eligibility', 'min_kyc_level',
        'allowed_tiers', 'is_active', 'sort_order', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'min_balance' => 'decimal:2',
            'min_contribution' => 'decimal:2',
            'penalty_value' => 'decimal:2',
            'allowed_terms' => 'array',
            'eligibility' => 'array',
            'allowed_tiers' => 'array',
            'meta' => 'array',
            'early_withdrawal_allowed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function goals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(SavingsVault::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class);
    }

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    /**
     * Expected simple interest for a principal.
     *
     * The rate is always interpreted through the configured `interest_period`
     * so nothing about the time basis is hardcoded:
     *   daily    -> 1 day
     *   monthly  -> 30 days
     *   annually -> 365 days
     *
     * Without an explicit term this returns the interest for ONE full period.
     */
    public function projectInterest(float $principal, ?int $days = null): float
    {
        $unitDays = match ($this->interest_period) {
            'daily' => 1,
            'annually' => 365,
            default => 30, // monthly, maturity
        };

        $factor = $days === null ? 1 : ($days / $unitDays);
        $rate = (float) $this->interest_rate;

        return round($principal * ($rate / 100) * $factor, 2);
    }
}
