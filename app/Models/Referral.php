<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    public const STATUS_CLICKED = 'clicked';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_KYC_PENDING = 'kyc_pending';
    public const STATUS_KYC_VERIFIED = 'kyc_verified';
    public const STATUS_FIRST_CONTRIBUTION_PENDING = 'first_contribution_pending';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REWARD_ISSUED = 'reward_issued';
    public const STATUS_REWARD_CANCELLED = 'reward_cancelled';

    protected $fillable = [
        'referrer_member_id', 'referee_user_id', 'referee_member_id',
        'code', 'status', 'clicked_at', 'registered_at', 'kyc_verified_at',
        'first_contribution_at', 'qualified_at', 'reward_points',
        'reward_cash', 'reward_issued_at', 'reward_entry_id',
        'cancellation_reason', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
            'registered_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'first_contribution_at' => 'datetime',
            'qualified_at' => 'datetime',
            'reward_issued_at' => 'datetime',
            'reward_points' => 'integer',
            'reward_cash' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'referrer_member_id');
    }

    public function refereeMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'referee_member_id');
    }

    public function refereeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }
}
