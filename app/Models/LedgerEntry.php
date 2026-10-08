<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable double-entry line. Rows are created once and never updated
 * except to stamp balance_after at write time.
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'transaction_id', 'ledger_account_id', 'member_id',
        'member_account_id', 'direction', 'amount', 'currency',
        'balance_after', 'memo', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function memberAccount(): BelongsTo
    {
        return $this->belongsTo(MemberAccount::class);
    }

    public function isDebit(): bool
    {
        return $this->direction === 'debit';
    }

    public function signedAmount(): float
    {
        return $this->isDebit() ? (float) $this->amount : -(float) $this->amount;
    }
}
