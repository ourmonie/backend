<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin writer for the audit trail (PRD 24, 42).
 *
 * Callers never touch balances through here - audit records are observations
 * of actions that already happened through LedgerService.
 */
class Auditor
{
    /**
     * @param  array<string, mixed>  $old  before snapshot
     * @param  array<string, mixed>  $new  after snapshot
     */
    public static function record(
        string $event,
        ?string $group = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
        ?Model $subject = null,
    ): ?AuditLog {
        try {
            $request = request();
            $user = $request?->user('sanctum');

            return AuditLog::create([
                'event' => $event,
                'group' => $group,
                'user_id' => $user?->id,
                'member_id' => $user?->member?->id,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'description' => $description !== null ? mb_substr($description, 0, 255) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent() !== null
                    ? mb_substr($request->userAgent(), 0, 255)
                    : null,
                'old_values' => $old ?: null,
                'new_values' => $new ?: null,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the action it is describing, but the
            // failure is surfaced to the log rather than swallowed silently.
            report($e);

            return null;
        }
    }
}
