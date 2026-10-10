<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Outbound email is configured from the dashboard, not .env (requirement 5).
     * Defaults keep sending off until an admin fills in and saves real settings.
     */
    private const SETTINGS = [
        ['mail.enabled', '0', 'boolean', 'Send email', 'Welcome and password-reset email through your own mail server.'],
        ['mail.host', '', 'string', 'SMTP host', 'e.g. smtp.gmail.com or the mail server of your hosting.'],
        ['mail.port', '587', 'integer', 'SMTP port', '587 for TLS, 465 for SSL.'],
        ['mail.encryption', 'tls', 'string', 'Encryption', 'tls, ssl or none.'],
        ['mail.username', '', 'string', 'SMTP username', 'Usually the full email address.'],
        ['mail.password', '', 'string', 'SMTP password', 'For Gmail, create a Google App Password - never your account password.'],
        ['mail.from_address', '', 'string', 'From address', 'What members see as the sender. Leave empty to use the SMTP username.'],
        ['mail.from_name', 'OurMonie', 'string', 'From name', 'What members see as the sender name.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type, $label, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['group' => 'mail', 'value' => $value, 'type' => $type, 'label' => $label, 'description' => $description, 'is_public' => false]
            );
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
