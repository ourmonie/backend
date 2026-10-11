<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Every rule and credential the dashboard edits. Seeded once so the key
     * exists with the right type, label and visibility; subsequent writes go
     * through Setting::put() and never change the declared type.
     *
     * [key, value, type, label, description, is_public]
     */
    private const SETTINGS = [
        /* ------------------------------- General ------------------------------ */
        ['general.app_name', 'OurMonie', 'string', 'Application name', 'Shown in the browser tab, emails and member-facing screens.', true],
        ['general.tagline', 'Save · Grow · Together', 'string', 'Tagline', 'Short line under the wordmark.', true],
        ['general.footer_text', '© OurMonie Cooperative. All rights reserved.', 'string', 'Footer text', 'Bottom of every public and member screen.', true],
        ['general.support_email', 'support@africanolympiads.com', 'string', 'Support email', 'Where members are directed for help.', true],
        ['general.support_phone', '', 'string', 'Support phone', 'Shown alongside the support email.', true],

        /* ------------------------------ Branding ------------------------------ */
        ['branding.logo_url', '', 'string', 'Logo URL', 'Absolute URL of the logo used in the header.', true],
        ['branding.favicon_url', '', 'string', 'Favicon URL', 'Absolute URL of the browser tab icon.', true],
        ['branding.seo_title', 'OurMonie - Cooperative Savings', 'string', 'SEO title', 'Default page title for search engines.', true],
        ['branding.seo_description', 'OurMonie is a digital cooperative savings platform for structured saving, growth and shared prosperity.', 'string', 'SEO description', 'Default meta description.', true],
        ['branding.theme_color', '#1f66f0', 'string', 'Brand colour', 'Primary accent across the product, as a hex value.', true],
        ['branding.font_family', 'satoshi', 'string', 'Typeface', 'Headline and body face.', true],
        ['branding.spacing_scale', 'comfortable', 'string', 'Spacing scale', 'Vertical rhythm of cards and sections.', true],
        ['branding.maintenance_mode', '0', 'boolean', 'Maintenance mode', 'Members see a maintenance notice; staff keep access.', true],
        ['branding.maintenance_message', 'We are undergoing scheduled maintenance. Please check back shortly.', 'string', 'Maintenance notice', 'Message members see while maintenance mode is on.', true],

        /* ------------------------------ Paystack ------------------------------ */
        ['paystack.enabled', '0', 'boolean', 'Paystack enabled', 'Accept Paystack as an active payment gateway.', false],
        ['paystack.mode', 'test', 'string', 'Paystack environment', 'test or live. Decides which key pair signs requests and webhooks.', false],
        ['paystack.test_public_key', '', 'string', 'Test public key', 'pk_test_… from the Paystack dashboard.', false],
        ['paystack.test_secret_key', '', 'string', 'Test secret key', 'sk_test_… Signs test requests and test webhooks.', false],
        ['paystack.test_webhook_secret', '', 'string', 'Test webhook secret', 'Optional. Leave empty to verify webhooks with the test secret key.', false],
        ['paystack.live_public_key', '', 'string', 'Live public key', 'pk_live_… from the Paystack dashboard.', false],
        ['paystack.live_secret_key', '', 'string', 'Live secret key', 'sk_live_… Signs live requests and live webhooks.', false],
        ['paystack.live_webhook_secret', '', 'string', 'Live webhook secret', 'Optional. Leave empty to verify webhooks with the live secret key.', false],
        ['paystack.preferred_bank', 'wema-bank', 'string', 'Preferred bank', 'Bank slug assigned to new dedicated virtual accounts.', false],

        /* ----------------------------- Flutterwave ---------------------------- */
        ['flutterwave.enabled', '0', 'boolean', 'Flutterwave enabled', 'Accept Flutterwave as an active payment gateway.', false],
        ['flutterwave.mode', 'test', 'string', 'Flutterwave environment', 'test or live. Decides which key pair signs requests and webhooks.', false],
        ['flutterwave.test_public_key', '', 'string', 'Test public key', 'FLWPUBTEST-… from the Flutterwave dashboard.', false],
        ['flutterwave.test_secret_key', '', 'string', 'Test secret key', 'FLWSECKTEST-… Signs test requests.', false],
        ['flutterwave.test_webhook_hash', '', 'string', 'Test webhook hash', 'Verif-hash value for test webhooks.', false],
        ['flutterwave.live_public_key', '', 'string', 'Live public key', 'FLWPUBLIVE-… from the Flutterwave dashboard.', false],
        ['flutterwave.live_secret_key', '', 'string', 'Live secret key', 'FLWSECKLIVE-… Signs live requests.', false],
        ['flutterwave.live_webhook_hash', '', 'string', 'Live webhook hash', 'Verif-hash value for live webhooks.', false],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type, $label, $description, $isPublic]) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'group' => explode('.', $key)[0],
                    'value' => $value,
                    'type' => $type,
                    'label' => $label,
                    'description' => $description,
                    'is_public' => $isPublic,
                ]
            );
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
