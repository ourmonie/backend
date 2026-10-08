<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycRecord extends Model
{
    /* PRD 18 - the configured lifecycle of a KYC submission. */
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REQUIRES_REVIEW = 'requires_review';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RESUBMISSION_REQUIRED = 'resubmission_required';

    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_PROCESSING,
        self::STATUS_REQUIRES_REVIEW,
    ];

    protected $fillable = [
        'member_id', 'level', 'status', 'data', 'documents', 'provider',
        'provider_reference', 'provider_response', 'submitted_at',
        'processed_at', 'reviewed_at', 'reviewed_by', 'rejection_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'documents' => 'array',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
