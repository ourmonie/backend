<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device that has granted this browser permission to be pushed to.
 *
 * The subscription is disposable by nature. Push services expire them, a
 * member can revoke permission from browser settings without ever telling
 * us, and a device can be wiped. So every send records whether it worked,
 * and a subscription the provider rejects is deleted rather than retried -
 * a dead endpoint would otherwise be addressed again on every notification
 * for as long as that member keeps using the product.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'p256dh', 'auth',
        'content_encoding', 'platform', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
