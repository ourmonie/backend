<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsVault extends Model
{
    protected $fillable = [
        'member_id', 'savings_product_id', 'member_account_id',
        'vault_number', 'principal', 'term_days', 'interest_rate',
        'interest_period', 'expected_return', 'interest_earned',
        'starts_on', 'matures_on', 'status', 'auto_renew',
        'penalty_applied', 'matured_at', 'withdrawn_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'expected_return' => 'decimal:2',
            'interest_earned' => 'decimal:2',
            'penalty_applied' => 'decimal:2',
            'starts_on' => 'date',
            'matures_on' => 'date',
            'matured_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'auto_renew' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MemberAccount::class, 'member_account_id');
    }

    public function hasMatured(): bool
    {
        return $this->matures_on !== null && $this->matures_on->isPast();
    }

    public function daysRemaining(): int
    {
        if ($this->hasMatured()) {
            return 0;
        }

        return (int) now()->diffInDays($this->matures_on, false);
    }
}
