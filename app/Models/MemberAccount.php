<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member's balance container for one financial product.
 * Balances are ONLY ever changed by LedgerService postings.
 */
class MemberAccount extends Model
{
    public const TYPE_WALLET = 'wallet';
    public const TYPE_SWIFT = 'swift_save';
    public const TYPE_QUEST = 'quest_save';
    public const TYPE_VAULT = 'time_vault';

    protected $fillable = [
        'member_id', 'type', 'account_number', 'balance',
        'total_in', 'total_out', 'currency', 'status',
        'ledger_account_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_in' => 'decimal:2',
            'total_out' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(SavingsVault::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_WALLET => 'Wallet',
            self::TYPE_SWIFT => 'SwiftSave',
            self::TYPE_QUEST => 'QuestSave',
            self::TYPE_VAULT => 'TimeVault',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    public function balanceAmount(): float
    {
        return (float) $this->balance;
    }
}
