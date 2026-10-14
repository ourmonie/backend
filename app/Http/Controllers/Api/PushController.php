<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where a member's browser agrees to be pushed to.
 *
 * The subscription is not a credential and cannot move money. What it does
 * hold is a device address that the push service issued, which is why the
 * whole payload is checked for shape before anything is stored: a row with
 * a malformed endpoint would be addressed on every wallet credit for as
 * long as that member stayed, and the send would fail every time.
 */
class PushController extends Controller
{
    /** The key the browser needs before it can ask for a subscription. */
    public function publicKey(): JsonResponse
    {
        if (! PushService::configured()) {
            return response()->json([
                'enabled' => false,
                'public_key' => null,
            ]);
        }

        return response()->json([
            'enabled' => true,
            'public_key' => PushService::publicKey(),
        ]);
    }

    /**
     * Store a browser's subscription against the signed-in user.
     *
     * Re-subscribing from the same browser updates the row in place. The
     * browser issues a fresh endpoint and fresh keys whenever it re-grants
     * permission, so keeping the old pair around would leave a dead target
     * the sender keeps trying on every notification.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:30'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => 'aes128gcm',
                'platform' => $validated['platform'] ?? null,
            ]
        );

        return response()->json([
            'subscribed' => true,
            'id' => $subscription->id,
        ]);
    }

    /**
     * Drop a subscription this browser no longer wants.
     *
     * Scoped to the signed-in user: a member may only ever remove their own
     * devices, so a crafted request cannot silence somebody else.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        DB::table('push_subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    /**
     * Whether this browser's device can still be reached.
     *
     * Used after login to decide whether to bother asking for permission
     * again, rather than prompting on every visit.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => PushService::configured(),
            'devices' => PushSubscription::where('user_id', $request->user()->id)->count(),
        ]);
    }
}
