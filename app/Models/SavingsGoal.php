<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoal extends Model
{
    protected $fillable = [
        'member_id', 'savings_product_id', 'member_account_id', 'name',
        'category', 'target_amount', 'current_amount', 'target_date',
        'auto_save', 'auto_save_amount', 'auto_save_frequency',
        'allow_external_contributions', 'status', 'interest_rate',
        'completed_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'auto_save_amount' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'auto_save' => 'boolean',
            'allow_external_contributions' => 'boolean',
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

    public function progressPercent(): float
    {
        if ((float) $this->target_amount <= 0) {
            return 0;
        }

        return min(100, round(((float) $this->current_amount / (float) $this->target_amount) * 100, 1));
    }

    public function remaining(): float
    {
        return max(0, (float) $this->target_amount - (float) $this->current_amount);
    }
}
