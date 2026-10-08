<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'membership_tier_id', 'member_number', 'referral_code',
        'membership_status', 'kyc_status', 'kyc_level', 'member_since',
        'approved_at', 'approved_by', 'gender', 'date_of_birth', 'avatar',
        'address', 'city', 'state', 'bvn', 'nin', 'occupation', 'employer',
        'job_title', 'employment_status', 'referred_by', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'member_since' => 'datetime',
            'approved_at' => 'datetime',
            'meta' => 'array',
            'kyc_level' => 'integer',
        ];
    }

    /* ------------------------------ relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'membership_tier_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'referred_by');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MemberAccount::class);
    }

    public function kycRecords(): HasMany
    {
        return $this->hasMany(KycRecord::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(SavingsVault::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function rewardAccount(): HasOne
    {
        return $this->hasOne(RewardAccount::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MemberNotification::class);
    }

    /**
     * Holds that are still in force: active, and not past their expiry.
     * PRD 20 - holds can be time-boxed, and an expired hold must stop blocking.
     */
    public function activeHolds(): HasMany
    {
        return $this->hasMany(AccountHold::class)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Every hold ever placed, including released and expired ones. */
    public function holds(): HasMany
    {
        return $this->hasMany(AccountHold::class);
    }

    /* -------------------------------- helpers ------------------------------- */

    public function isVerified(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function isActive(): bool
    {
        return $this->membership_status === 'active';
    }

    public function hasBlockingHold(): bool
    {
        return $this->activeHolds()
            ->where('hold_type', 'full_suspension')
            ->exists();
    }

    public function displayName(): string
    {
        return $this->user?->name ?? 'Member';
    }
}
