<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberNotification;
use Illuminate\Support\Facades\DB;

/**
 * PRD 35 - notification events. The in-app feed is written here, and the
 * push channel is fanned out from the same call so a caller never has to
 * remember to do it twice. Email/SMS/WhatsApp will deliver from the same
 * `channel` field once those transports are configured, so the event
 * catalog stays channel-agnostic.
 *
 * PRD 18 requires the member to be notified of every KYC outcome; other
 * services raise their own events through here rather than inserting rows.
 */
class Notifier
{
    /**
     * Event families worth interrupting someone for.
     *
     * Push is not a second copy of the in-app feed - it is a tap on the
     * shoulder, and the shoulder belongs to the member. Money moving, an
     * identity decision, and changes to whether the account can transact
     * are the three things a member would want to be woken for. Referral
     * milestones, goal progress and promotions are not, and a member buzzed
     * for trivia turns notifications off at the OS level - at which point
     * the wallet credit they actually cared about stops reaching them too.
     *
     * So the rule lives here rather than at each call site: a new event
     * type is in-app only until someone puts its name on this list.
     *
     * @var array<int, string>
     */
    private const PUSH_FAMILIES = ['wallet.', 'withdrawal.', 'transfer.', 'kyc.', 'membership.', 'account.'];

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
        $notification = MemberNotification::create([
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

        if (! self::shouldPush($type) || $member->user_id === null) {
            return $notification;
        }

        // Deferred to after commit, and only if the surrounding transaction
        // commits. A KYC decision is notified inside the same transaction
        // that saves it, so pushing immediately would announce an approval
        // that a rollback a moment later had never granted - the member
        // would read a push saying they are verified and still be unable to
        // withdraw. In-app has the opposite problem and is fine either way:
        // it is written in the transaction, so it disappears with a rollback
        // exactly as it should.
        $userId = $member->user_id;

        try {
            DB::afterCommit(function () use ($userId, $title, $body, $url, $data, $type) {
                PushService::sendToUser($userId, $title, $body, $url, $data, $type);
            });
        } catch (\Throwable $exception) {
            // Push is best effort by construction. A failure to even
            // schedule it - a manager not available in some console
            // context, for instance - must not reach the caller, because
            // several of these calls sit inside a DB::transaction and an
            // exception there would roll back the very decision the member
            // is being told about.
            report($exception);
        }

        return $notification;
    }

    private static function shouldPush(string $type): bool
    {
        foreach (self::PUSH_FAMILIES as $family) {
            if (str_starts_with($type, $family)) {
                return true;
            }
        }

        return false;
    }
}
