<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Best-effort delivery of a notification to a member's locked screen.
 *
 * This is a second channel onto a notification that has already been
 * written to the in-app feed, never a replacement for it. Every call here
 * is wrapped so that a push that cannot be sent - no keys configured, the
 * push service unreachable, the device offline - leaves the originating
 * operation completely unaffected. A wallet credit must never fail to be
 * recorded because a phone could not be buzzed, and a KYC approval must
 * never be held up by it.
 *
 * Subscriptions die quietly and often. A member revokes permission from
 * browser settings without telling us, a push service expires an endpoint,
 * a device is wiped. So a subscription the provider rejects with 404 or
 * 410 is deleted on the spot rather than kept and addressed again on every
 * notification for as long as that member keeps using the product.
 */
class PushService
{
    private const GROUP = 'push';

    /* ---------------------------------------------------------------------- */
    /* Keys                                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * The VAPID pair is generated once, by ourmonie:generate-vapid, and kept
     * in settings beside the gateway secrets. It is what proves to the push
     * service that this application, and only this application, may send to
     * an endpoint it issued.
     */
    public static function configured(): bool
    {
        return self::publicKey() !== null && self::privateKey() !== null;
    }

    public static function publicKey(): ?string
    {
        return self::read('public_key');
    }

    private static function privateKey(): ?string
    {
        return self::read('private_key');
    }

    /** Where complaints about these messages should be sent, per VAPID. */
    private static function subject(): string
    {
        $subject = trim((string) Setting::getValue(self::GROUP.'.subject', ''));

        if ($subject === '') {
            $subject = 'mailto:support@africanolympiads.com';
        }

        return $subject;
    }

    /* ---------------------------------------------------------------------- */
    /* Sending                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * Push to every device this user has subscribed.
     *
     * Silently does nothing when VAPID has not been generated, when the user
     * has no subscriptions, or when every send fails. Returns the number of
     * devices reached, which is only ever of interest to a log line.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $url = null,
        array $data = [],
        string $tag = 'notification',
    ): int {
        if (! self::configured()) {
            return 0;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webpush = new WebPush([
                'VAPID' => [
                    'subject' => self::subject(),
                    'publicKey' => self::publicKey(),
                    'privateKey' => self::privateKey(),
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return 0;
        }

        // Kept deliberately small: the whole payload is encrypted into a
        // single POST, and the push services cap it at about 4KB.
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => $tag,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivered = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $report = $webpush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->p256dh,
                        'authToken' => $subscription->auth,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    $payload
                );
            } catch (\Throwable $exception) {
                // A transport failure says nothing about whether the device
                // still wants these. Only an answer from the push service
                // can retire a subscription, so this one is left alone.
                Log::warning('Web push send failed.', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();
                $delivered++;

                continue;
            }

            if (self::isGone($report)) {
                $subscription->delete();

                continue;
            }

            Log::warning('Web push rejected.', [
                'user_id' => $userId,
                'reason' => $report->getReason(),
                'status' => $report->getResponse()?->getStatusCode(),
            ]);
        }

        return $delivered;
    }

    /**
     * 404 and 410 are the push service saying this endpoint no longer exists.
     *
     * Anything else - 429, 5xx - is transient, and the subscription is worth
     * keeping for the next notification.
     */
    private static function isGone(MessageSentReport $report): bool
    {
        if ($report->isSubscriptionExpired()) {
            return true;
        }

        $status = $report->getResponse()?->getStatusCode();

        return $status === 404 || $status === 410;
    }

    private static function read(string $key): ?string
    {
        $value = trim((string) Setting::getValue(self::GROUP.'.'.$key, ''));

        return $value === '' ? null : $value;
    }
}
