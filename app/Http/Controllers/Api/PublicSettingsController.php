<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * What an unauthenticated visitor needs before the app renders: the name on
 * the door, the artwork, the search snippet, and whether the product is
 * closed for maintenance.
 *
 * Public by design, and deliberately narrow. Every key served here is seeded
 * with `is_public = true`; credentials, gateway settings and anything the
 * dashboard marks private have no path into this response.
 */
class PublicSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'app_name' => $this->read('general.app_name', 'OurMonie'),
            'tagline' => $this->read('general.tagline', 'Save · Grow · Together'),
            'footer_text' => $this->read('general.footer_text', ''),
            'support_email' => $this->read('general.support_email', ''),
            'support_phone' => $this->read('general.support_phone', ''),
            'branding' => [
                'logo_url' => $this->read('branding.logo_url', ''),
                'favicon_url' => $this->read('branding.favicon_url', ''),
                'seo_title' => $this->read('branding.seo_title', ''),
                'seo_description' => $this->read('branding.seo_description', ''),
                'theme_color' => $this->read('branding.theme_color', '#1f66f0'),
                'font_family' => $this->read('branding.font_family', 'satoshi'),
                'spacing_scale' => $this->read('branding.spacing_scale', 'comfortable'),
            ],
            'maintenance' => [
                'enabled' => (bool) Setting::getValue('branding.maintenance_mode', false),
                'message' => $this->read(
                    'branding.maintenance_message',
                    'We are undergoing scheduled maintenance. Please check back shortly.'
                ),
            ],
        ]);
    }

    private function read(string $key, string $default): string
    {
        $value = Setting::getValue($key, $default);

        return is_string($value) ? $value : $default;
    }
}
