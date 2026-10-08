<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardEntry extends Model
{
    protected $fillable = [
        'reward_account_id', 'member_id', 'reward_rule_id', 'entry_type',
        'points', 'balance_after', 'activity', 'reference', 'description',
        'transaction_id', 'created_by', 'expires_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(RewardAccount::class, 'reward_account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RewardRule::class, 'reward_rule_id');
    }
}
