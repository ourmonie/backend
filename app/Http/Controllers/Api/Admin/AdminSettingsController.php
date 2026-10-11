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
        'general' => [
            'app_name' => 'general.app_name',
            'tagline' => 'general.tagline',
            'footer_text' => 'general.footer_text',
            'support_email' => 'general.support_email',
            'support_phone' => 'general.support_phone',
        ],
        'branding' => [
            'logo_url' => 'branding.logo_url',
            'favicon_url' => 'branding.favicon_url',
            'seo_title' => 'branding.seo_title',
            'seo_description' => 'branding.seo_description',
            'theme_color' => 'branding.theme_color',
            'font_family' => 'branding.font_family',
            'spacing_scale' => 'branding.spacing_scale',
            'maintenance_mode' => 'branding.maintenance_mode',
            'maintenance_message' => 'branding.maintenance_message',
        ],
        'paystack' => [
            'enabled' => 'paystack.enabled',
            'mode' => 'paystack.mode',
            'test_public_key' => 'paystack.test_public_key',
            'test_secret_key' => 'paystack.test_secret_key',
            'test_webhook_secret' => 'paystack.test_webhook_secret',
            'live_public_key' => 'paystack.live_public_key',
            'live_secret_key' => 'paystack.live_secret_key',
            'live_webhook_secret' => 'paystack.live_webhook_secret',
            'preferred_bank' => 'paystack.preferred_bank',
        ],
        'flutterwave' => [
            'enabled' => 'flutterwave.enabled',
            'mode' => 'flutterwave.mode',
            'test_public_key' => 'flutterwave.test_public_key',
            'test_secret_key' => 'flutterwave.test_secret_key',
            'test_webhook_hash' => 'flutterwave.test_webhook_hash',
            'live_public_key' => 'flutterwave.live_public_key',
            'live_secret_key' => 'flutterwave.live_secret_key',
            'live_webhook_hash' => 'flutterwave.live_webhook_hash',
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
    private function rules(string $group): array    {
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

        if ($group === 'general') {
            return [
                'app_name' => ['sometimes', 'string', 'max:60'],
                'tagline' => ['sometimes', 'string', 'max:120'],
                'footer_text' => ['sometimes', 'string', 'max:200'],
                'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
                'support_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            ];
        }

        if ($group === 'branding') {
            return [
                'logo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:300'],
                'favicon_url' => ['sometimes', 'nullable', 'url:http,https', 'max:300'],
                'seo_title' => ['sometimes', 'string', 'max:70'],
                'seo_description' => ['sometimes', 'string', 'max:170'],
                'theme_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'font_family' => ['sometimes', 'string', Rule::in(['satoshi', 'inter', 'system'])],
                'spacing_scale' => ['sometimes', 'string', Rule::in(['compact', 'comfortable', 'relaxed'])],
                'maintenance_mode' => ['sometimes', 'boolean'],
                'maintenance_message' => ['sometimes', 'string', 'max:200'],
            ];
        }

        if ($group === 'paystack') {
            return $this->gatewayRules('paystack');
        }

        if ($group === 'flutterwave') {
            return $this->gatewayRules('flutterwave');
        }

        return self::RULES[$group];
    }

    /**
     * Both gateways share one shape: an on/off, an environment, and two
     * complete key sets. `mode` is constrained to the two environments so a
     * crafted payload can never point the server at an unrecognised one, and
     * every key is optional - a gateway may be saved half-configured and
     * switched on only once both halves are there.
     */
    private function gatewayRules(string $gateway): array
    {
        $suffix = $gateway === 'paystack' ? 'webhook_secret' : 'webhook_hash';

        return [
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', Rule::in(['test', 'live'])],
            'test_public_key' => ['nullable', 'string', 'max:120'],
            'test_secret_key' => ['nullable', 'string', 'max:120'],
            'test_'.$suffix => ['nullable', 'string', 'max:120'],
            'live_public_key' => ['nullable', 'string', 'max:120'],
            'live_secret_key' => ['nullable', 'string', 'max:120'],
            'live_'.$suffix => ['nullable', 'string', 'max:120'],
            'preferred_bank' => ['nullable', 'string', 'max:60'],
        ];
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

        // Domain lists are typed by hand - trim and lowercase them before
        // the rules run, so "Gmail.COM" simply saves as "gmail.com".
        if (is_array($request->input('allowed_email_domains'))) {
            $request->merge([
                'allowed_email_domains' => array_values(array_filter(array_map(
                    fn ($domain) => is_string($domain) ? strtolower(trim($domain)) : $domain,
                    $request->input('allowed_email_domains')
                ))),
            ]);
        }

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

            // Secrets leave the server masked. The form sends that same
            // masked string back untouched when the admin did not retype the
            // key, and writing it would destroy the real value - so it is
            // recognised and dropped here instead. An empty string still
            // clears the key deliberately.
            if (self::isSecret($key) && is_string($value)) {
                if ($value === $this->maskSecret($this->raw($key))) {
                    continue;
                }

                if ($value === '') {
                    Setting::put($key, null, $group);

                    continue;
                }
            }

            // Booleans arrive from the form as true/false; store the canonical
            // representation the settings reader expects.
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Setting::put($key, $value, $group);
        }

        // Switching an environment or pasting a key must be reflected in the
        // readiness the Payments screen reads immediately after saving.
        if (in_array($group, ['paystack', 'flutterwave'], true)) {
            $this->reportGatewayReady($group);
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
            $raw = $this->raw($key);

            if (self::isSecret($key)) {
                // Only a hint of the key ever crosses the wire.
                $values[$field] = $raw === null ? null : $this->maskSecret($raw);
                $values[$field.'_set'] = $raw !== null && $raw !== '';

                continue;
            }

            $setting = Setting::where('key', $key)->first();

            $values[$field] = $setting === null ? null : Setting::typecast($setting);
        }

        return $values;
    }

    private function raw(string $key): ?string
    {
        $setting = Setting::where('key', $key)->first();

        return $setting === null ? null : (string) $setting->value;
    }

    /**
     * A key the dashboard must never echo back in full. Pattern-matched
     * rather than enumerated so a credential added later is protected by
     * default rather than by remembering to list it.
     */
    private static function isSecret(string $key): bool
    {
        return str_ends_with($key, '_secret_key')
            || str_ends_with($key, '_webhook_secret')
            || str_ends_with($key, '_webhook_hash')
            || str_ends_with($key, '.password')
            || str_ends_with($key, '.client_secret')
            || str_ends_with($key, '.dojah_secret_key');
    }

    /** sk_live_abcdef123456 -> sk_live_…3456 */
    private function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 7).'…'.substr($value, -4);
    }

    /**
     * Tell the admin plainly whether the environment they just saved can
     * actually sign a request, so "activated" never means "half filled in".
     */
    private function reportGatewayReady(string $gateway): void
    {
        $mode = (string) Setting::getValue($gateway.'.mode', 'test');
        $secret = (string) Setting::getValue($gateway.'.'.$mode.'_secret_key', '');

        if ($secret !== '' && ! (bool) Setting::getValue($gateway.'.enabled', false)) {
            // Not an error: keys can be staged before the switch is thrown.
            Log::info('Gateway keys saved but gateway is still disabled.', [
                'gateway' => $gateway,
                'mode' => $mode,
            ]);
        }
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
