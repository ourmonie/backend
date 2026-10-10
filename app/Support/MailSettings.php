<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Outbound email, configured entirely from the admin dashboard (requirement 5).
 *
 * Laravel reads mail settings from the config repository, which is compiled
 * from .env and often cached - so before every send we copy the settings
 * table's values into the runtime config. Nothing here needs a redeploy,
 * a .env edit or a config:cache refresh when credentials rotate.
 */
class MailSettings
{
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('mail.enabled', false);
    }

    /**
     * Copy the dashboard settings into the runtime mail config.
     * Returns false when email is switched off (callers decide what that means).
     */
    public static function apply(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $host = trim((string) Setting::getValue('mail.host', ''));
        $username = trim((string) Setting::getValue('mail.username', ''));
        $encryption = (string) Setting::getValue('mail.encryption', 'tls');

        if ($host === '') {
            return false;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', (int) Setting::getValue('mail.port', 587));
        Config::set('mail.mailers.smtp.encryption', in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', (string) Setting::getValue('mail.password', ''));
        Config::set('mail.from.address', trim((string) Setting::getValue('mail.from_address', '')) ?: $username);
        Config::set('mail.from.name', trim((string) Setting::getValue('mail.from_name', '')) ?: 'OurMonie');

        return true;
    }

    /**
     * Fire one raw-HTML message. Returns false when email is switched off;
     * transport failures bubble up so the caller can decide whether the
     * failure is fatal (admin test) or best effort (welcome email).
     */
    public static function send(string $to, string $subject, string $html): bool
    {
        if (! self::apply()) {
            return false;
        }

        Mail::raw($html, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return true;
    }
}
