<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The member's primary NGN wallet, including the dedicated account
 * number used to receive bank transfers. The Paystack DVA is a funding
 * channel only - it is never the source of truth for the balance.
 */
class Wallet extends Model
{
    protected $fillable = [
        'member_id', 'member_account_id', 'account_number', 'account_name',
        'bank_name', 'provider', 'provider_customer_code',
        'provider_reference', 'status', 'assigned_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MemberAccount::class, 'member_account_id');
    }

    public function balance(): float
    {
        return (float) ($this->account?->balance ?? 0);
    }
}
