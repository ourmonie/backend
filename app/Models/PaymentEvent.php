<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provider event we have ever been sent. The unique `event_id`
 * is the idempotency key: Paystack retries every few minutes for 72 hours,
 * Flutterwave re-sends on a slow 200, and a member may sit on a pending
 * screen refreshing. None of them can move money twice, because the second
 * claim on the same event_id loses.
 */
class PaymentEvent extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'event_id', 'event_type', 'provider', 'reference', 'status',
        'payload', 'error', 'transaction_id', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Claim an event so only one worker ever processes it.
     * Returns null when another request already holds this event_id.
     */
    public static function claim(string $eventId, string $eventType, string $provider, ?string $reference = null): ?self
    {
        try {
            return static::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'provider' => $provider,
                'reference' => $reference,
                'status' => self::STATUS_RECEIVED,
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            // Unique constraint - someone else is on it, or already was.
            if ((int) $exception->errorInfo[1] === 1062) {
                return null;
            }

            throw $exception;
        }
    }
}
