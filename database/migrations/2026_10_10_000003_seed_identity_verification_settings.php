<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * How the age gate is proved. `manual` records the NIN and trusts the
     * declared date of birth; `dojah` verifies the number live against NIMC
     * and reads the holder's date of birth from the register.
     */
    private const SETTINGS = [
        ['nin.mode', 'manual', 'string', 'NIN verification mode', 'Recorded only, or verified live against NIMC through Dojah.'],
        ['nin.dojah_app_id', '', 'string', 'Dojah App ID', 'App ID from your Dojah dashboard.'],
        ['nin.dojah_secret_key', '', 'string', 'Dojah secret key', 'Secret key from your Dojah dashboard.'],
        ['nin.dojah_sandbox', '1', 'boolean', 'Sandbox mode', 'Test against Dojah sandbox records. Switch off for live verification, which is billed per check.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type, $label, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['group' => 'nin', 'value' => $value, 'type' => $type, 'label' => $label, 'description' => $description, 'is_public' => false]
            );
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
