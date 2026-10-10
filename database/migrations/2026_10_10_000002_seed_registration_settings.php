<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Registration policy. Every requirement here can be switched off from
     * the admin dashboard (seamless mode), never from the .env file.
     */
    private const SETTINGS = [
        ['registration.seamless', '0', 'boolean', 'Seamless registration', 'Allow people to join without a referral code or identity checks.'],
        ['registration.require_referral', '1', 'boolean', 'Referral required', 'A valid code from an existing member is required to join.'],
        ['registration.require_nin', '1', 'boolean', 'Identity (NIN) required', 'New members must supply a National Identification Number and be 18 or older.'],
        ['registration.min_age', '18', 'integer', 'Minimum age', 'Membership is restricted to adults.'],
        ['registration.allowed_email_domains', '["gmail.com","yahoo.com","hotmail.com"]', 'array', 'Accepted email providers', 'Only email addresses from these providers may register.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type, $label, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['group' => 'registration', 'value' => $value, 'type' => $type, 'label' => $label, 'description' => $description, 'is_public' => true]
            );
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
