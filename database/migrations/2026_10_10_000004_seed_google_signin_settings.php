<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Google sign-in is configured from the admin dashboard, not .env, so
     * rotating a secret never means redeploying the API.
     */
    private const SETTINGS = [
        ['auth.google_enabled', '0', 'boolean', 'Google sign-in', 'Let people sign in and join with their Google account.'],
        ['auth.google_client_id', '', 'string', 'Google client ID', 'OAuth 2.0 client ID from Google Cloud Console.'],
        ['auth.google_client_secret', '', 'string', 'Google client secret', 'OAuth 2.0 client secret from Google Cloud Console.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type, $label, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['group' => 'auth', 'value' => $value, 'type' => $type, 'label' => $label, 'description' => $description, 'is_public' => false]
            );
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
