<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeConfig extends Model
{
    protected $fillable = [
        'code', 'name', 'applies_to', 'calculation', 'rate',
        'flat_amount', 'min_amount', 'max_amount', 'cap_amount',
        'bearer_member', 'tiers', 'is_active', 'ledger_account_id',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'flat_amount' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'cap_amount' => 'decimal:2',
            'tiers' => 'array',
            'bearer_member' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function byCode(string $code): ?self
    {
        return static::where('code', $code)->where('is_active', true)->first();
    }

    /**
     * Compute the fee for an amount using the configured calculation.
     * Returns 0 when no active rule matches - never a hardcoded fallback.
     */
    public function calculate(float $amount): float
    {
        $fee = match ($this->calculation) {
            'percent' => $amount * ((float) $this->rate / 100),
            default => (float) $this->flat_amount,
        };

        if ($this->min_amount !== null) {
            $fee = max($fee, (float) $this->min_amount);
        }
        if ($this->max_amount !== null) {
            $fee = min($fee, (float) $this->max_amount);
        }
        if ($this->cap_amount !== null) {
            $fee = min($fee, (float) $this->cap_amount);
        }

        return round($fee, 2);
    }
}
