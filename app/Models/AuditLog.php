<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PRD 24/42 - every sensitive administrative action leaves an audit record
 * with the actor, the subject, and a before/after snapshot.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'event',
        'group',
        'user_id',
        'member_id',
        'auditable_type',
        'auditable_id',
        'description',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
