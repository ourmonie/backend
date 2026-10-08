<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountHold extends Model
{
    public const TYPES = [
        'full_suspension',
        'transaction_restriction',
        'withdrawal_restriction',
        'funding_restriction',
        'temporary_hold',
    ];

    protected $fillable = [
        'member_id', 'hold_type', 'reason_code', 'reason', 'is_active',
        'placed_by', 'starts_at', 'expires_at', 'released_by',
        'released_at', 'release_note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * True when the hold is currently in force (active and not expired).
     */
    public function inForce(): bool
    {
        return (bool) $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * True when the hold currently blocks the given capability.
     */
    public function blocks(string $capability): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return $this->hold_type === 'full_suspension'
            || $this->hold_type === $capability;
    }
}
