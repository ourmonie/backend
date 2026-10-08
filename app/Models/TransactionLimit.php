<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLimit extends Model
{
    protected $fillable = [
        'code', 'transaction_type', 'scope_type', 'scope_id',
        'min_amount', 'max_amount', 'daily_limit', 'monthly_limit',
        'daily_count', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'daily_limit' => 'decimal:2',
            'monthly_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Resolve the limit applicable to a member for a transaction type.
     * Order: tier scope beats KYC scope beats product scope beats global.
     */
    public static function resolve(string $type, int $kycLevel, ?int $tierId = null): ?self
    {
        $query = static::where('transaction_type', $type)->where('is_active', true);

        if ($tierId !== null) {
            $tierMatch = (clone $query)->where('scope_type', 'tier')->where('scope_id', $tierId)->first();
            if ($tierMatch !== null) {
                return $tierMatch;
            }
        }

        $kycMatch = (clone $query)->where('scope_type', 'kyc')->where('scope_id', $kycLevel)->first();
        if ($kycMatch !== null) {
            return $kycMatch;
        }

        return $query->where('scope_type', 'global')->first();
    }
}
