<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberNotification;

/**
 * PRD 35 - notification events. Today only the in-app channel is written;
 * email/SMS/WhatsApp deliver from the same `channel` field once those
 * transports are configured, so the event catalog is channel-agnostic.
 *
 * PRD 18 requires the member to be notified of every KYC outcome; other
 * services raise their own events through here rather than inserting rows.
 */
class Notifier
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function notify(
        Member $member,
        string $type,
        string $title,
        string $body,
        string $severity = 'info',
        ?string $url = null,
        array $data = [],
    ): MemberNotification {
        return MemberNotification::create([
            'member_id' => $member->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'channel' => 'in_app',
            'severity' => $severity,
            'url' => $url,
            'data' => $data ?: null,
            'is_read' => false,
        ]);
    }
}
