<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending request to move a member's sign-in address. Nothing changes on the
 * account until the link we sent to the NEW address is clicked, so a session
 * that is not the member's cannot quietly reroute their mail.
 */
class EmailChangeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'old_email',
        'new_email',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Live means: never clicked, and not yet past its 60-minute window. */
    public function scopeLive($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
