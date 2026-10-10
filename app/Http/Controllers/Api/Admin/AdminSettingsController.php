<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\NinVerification;
use App\Support\EmailTemplates;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard-editable configuration (PRD 31). Every rule that gates a member
 * - referral, identity, accepted email providers - and the credentials behind
 * the checks are stored in the settings table and edited here. Nothing in
 * this system is configured through the .env file.
 *
 * GET renders a group; PUT writes only the keys that group declares, so a
 * crafted payload can never poison an unrelated setting.
 */
class AdminSettingsController extends Controller
{
    /** Short field name => full settings key, per group. */
    private const FIELDS = [
        'registration' => [
            'seamless' => 'registration.seamless',
            'require_referral' => 'registration.require_referral',
            'require_nin' => 'registration.require_nin',
            'min_age' => 'registration.min_age',
            'allowed_email_domains' => 'registration.allowed_email_domains',
        ],
        'nin' => [
            'mode' => 'nin.mode',
            'dojah_app_id' => 'nin.dojah_app_id',
            'dojah_secret_key' => 'nin.dojah_secret_key',
            'dojah_sandbox' => 'nin.dojah_sandbox',
        ],
        'auth' => [
            'google_enabled' => 'auth.google_enabled',
            'google_client_id' => 'auth.google_client_id',
            'google_client_secret' => 'auth.google_client_secret',
        ],
        'mail' => [
            'enabled' => 'mail.enabled',
            'host' => 'mail.host',
            'port' => 'mail.port',
            'encryption' => 'mail.encryption',
            'username' => 'mail.username',
            'password' => 'mail.password',
            'from_address' => 'mail.from_address',
            'from_name' => 'mail.from_name',
        ],
    ];

    private const RULES = [
        'registration' => [
            'seamless' => ['sometimes', 'boolean'],
            'require_referral' => ['sometimes', 'boolean'],
            'require_nin' => ['sometimes', 'boolean'],
            'min_age' => ['sometimes', 'integer', 'min:18', 'max:100'],
            'allowed_email_domains' => ['sometimes', 'array', 'min:1', 'max:20'],
            'allowed_email_domains.*' => ['string', 'max:60', 'lowercase'],
        ],
    ];

    /** Rule sets that need a runtime expression cannot be constants. */
    private function rules(string $group): array
    {
        if ($group === 'nin') {
            return [
                'mode' => ['sometimes', 'string', Rule::in([NinVerification::MODE_MANUAL, NinVerification::MODE_DOJAH])],
                'dojah_app_id' => ['nullable', 'string', 'max:120'],
                'dojah_secret_key' => ['nullable', 'string', 'max:200'],
                'dojah_sandbox' => ['sometimes', 'boolean'],
            ];
        }

        if ($group === 'auth') {
            return [
                'google_enabled' => ['sometimes', 'boolean'],
                'google_client_id' => ['nullable', 'string', 'max:250'],
                'google_client_secret' => ['nullable', 'string', 'max:250'],
            ];
        }

        if ($group === 'mail') {
            return [
                'enabled' => ['sometimes', 'boolean'],
                'host' => ['nullable', 'string', 'max:190'],
                'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
                'encryption' => ['sometimes', 'string', Rule::in(['tls', 'ssl', 'none'])],
                'username' => ['nullable', 'string', 'max:190'],
                'password' => ['nullable', 'string', 'max:190'],
                'from_address' => ['nullable', 'email:rfc', 'max:190'],
                'from_name' => ['nullable', 'string', 'max:120'],
            ];
        }

        return self::RULES[$group];
    }

    /**
     * Send a live test message through the saved settings so an admin knows
     * the channel works before the first member depends on it.
     */
    public function test(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => ['required', 'string', 'email:rfc', 'max:190'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        if (! MailSettings::enabled()) {
            throw ValidationException::withMessages([
                'to' => 'Switch outbound email on and save your settings first.',
            ]);
        }

        try {
            MailSettings::send(
                (string) $request->input('to'),
                'Your OurMonie email settings work',
                EmailTemplates::test()
            );
        } catch (\Throwable $exception) {
            // The admin is debugging their own server - the transport error
            // is exactly what they need to see.
            Log::warning('Admin SMTP test failed.', ['error' => $exception->getMessage()]);

            throw ValidationException::withMessages([
                'to' => 'We could not send through those settings: '.$exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Test email sent to '.$request->input('to').'.',
        ]);
    }

    /** One group, ready to bind straight to a form. */
    public function show(string $group): JsonResponse
    {
        $this->guard($group);

        return response()->json([
            'group' => $group,
            'settings' => $this->serialize($group),
            'meta' => $this->meta($group),
            // Google needs this exact value registered as an authorized
            // redirect URI, so the screen shows it for copying.
            'redirect_uri' => $group === 'auth' ? route('auth.google.callback') : null,
        ]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $this->guard($group);

        $validator = Validator::make($request->all(), $this->rules($group));

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $payload = $request->all();

        foreach (self::FIELDS[$group] as $field => $key) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            // Booleans arrive from the form as true/false; store the canonical
            // representation the settings reader expects.
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Setting::put($key, $value, $group);
        }

        return response()->json([
            'message' => 'Settings saved.',
            'group' => $group,
            'settings' => $this->serialize($group),
        ]);
    }

    /* ------------------------------ Helpers ------------------------------ */

    private function guard(string $group): void
    {
        abort_unless(array_key_exists($group, self::FIELDS), 404);
    }

    /** Short-keyed, correctly typed values plus their labels for the UI. */
    private function serialize(string $group): array
    {
        $values = [];

        foreach (self::FIELDS[$group] as $field => $key) {
            $setting = Setting::where('key', $key)->first();

            $values[$field] = $setting === null ? null : Setting::typecast($setting);
        }

        return $values;
    }

    private function meta(string $group): array
    {
        return Setting::whereIn('key', array_values(self::FIELDS[$group]))
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [
                str_replace($setting->group.'.', '', $setting->key) => [
                    'label' => $setting->label,
                    'description' => $setting->description,
                ],
            ])
            ->all();
    }
}
