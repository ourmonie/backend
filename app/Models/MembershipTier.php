<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipTier extends Model
{
    protected $fillable = [
        'code', 'name', 'min_threshold', 'max_threshold', 'benefits',
        'max_wallet_funding', 'max_transfer', 'max_withdrawal',
        'rewards_multiplier', 'sort_order', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_threshold' => 'decimal:2',
            'max_threshold' => 'decimal:2',
            'max_wallet_funding' => 'decimal:2',
            'max_transfer' => 'decimal:2',
            'max_withdrawal' => 'decimal:2',
            'rewards_multiplier' => 'decimal:2',
            'benefits' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * Resolve the tier a given total-savings figure belongs to.
     * Thresholds are admin-configured and never hardcoded.
     */
    public static function resolveForAmount(float $amount): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('min_threshold', '<=', $amount)
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_threshold')->orWhere('max_threshold', '>=', $amount);
            })
            ->orderByDesc('min_threshold')
            ->first();
    }
}
