<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /* Transaction types (mirrors ledger semantics, not a DB enum) */
    public const WALLET_FUNDING = 'wallet_funding';
    public const SAVINGS_DEPOSIT = 'savings_deposit';
    public const SAVINGS_WITHDRAWAL = 'savings_withdrawal';
    public const WALLET_WITHDRAWAL = 'wallet_withdrawal';
    public const INTERNAL_TRANSFER = 'internal_transfer';
    public const CONTRIBUTION = 'contribution';
    public const INTEREST = 'interest';
    public const FEE = 'fee';
    public const REWARD_CONVERSION = 'reward_conversion';
    public const REFERRAL_REWARD = 'referral_reward';
    public const ADJUSTMENT = 'adjustment';
    public const REVERSAL = 'reversal';
    public const REFUND = 'refund';
    public const SETTLEMENT = 'settlement';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'reference', 'member_id', 'type', 'direction', 'amount',
        'fee_amount', 'net_amount', 'currency', 'status', 'channel',
        'source', 'member_account_id', 'description', 'narration',
        'counterparty', 'provider_reference', 'created_by', 'approved_by',
        'posted_at', 'occurred_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'occurred_at' => 'datetime',
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

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Unique business reference. Never derived from user input.
     */
    public static function generateReference(string $prefix = 'OM'): string
    {
        do {
            $reference = $prefix.'_'.strtoupper(bin2hex(random_bytes(6)));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
