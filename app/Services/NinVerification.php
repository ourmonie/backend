<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Age gate (18+) with a real identity behind it.
 *
 * Two modes, chosen by the admin on the Settings page:
 *
 *  - manual  - the NIN is recorded for the audit trail and the applicant's
 *              declared date of birth decides whether they are over 18.
 *  - dojah   - the number is verified live against NIMC through Dojah and
 *              the date of birth comes from the register, not the form.
 *
 * Credentials live in the settings table (never in .env), so the whole flow
 * can be switched on, tested in sandbox and then put live from the dashboard.
 */
class NinVerification
{
    public const MODE_MANUAL = 'manual';
    public const MODE_DOJAH = 'dojah';

    public static function mode(): string
    {
        $mode = (string) Setting::getValue('nin.mode', self::MODE_MANUAL);

        return in_array($mode, [self::MODE_MANUAL, self::MODE_DOJAH], true) ? $mode : self::MODE_MANUAL;
    }

    public static function isLive(): bool
    {
        return self::mode() === self::MODE_DOJAH;
    }

    /**
     * Verify a NIN and return the NIMC record.
     *
     * @return array{date_of_birth: string, first_name: ?string, last_name: ?string, gender: ?string}
     *
     * @throws NinVerificationException with a message written for the applicant
     */
    public function verify(string $nin): array
    {
        $appId = trim((string) Setting::getValue('nin.dojah_app_id', ''));
        $secret = trim((string) Setting::getValue('nin.dojah_secret_key', ''));
        $sandbox = (bool) Setting::getValue('nin.dojah_sandbox', true);

        if ($appId === '' || $secret === '') {
            // Misconfiguration, not an applicant problem - log it, then say so plainly.
            Log::error('NIN verification requested without Dojah credentials configured.');

            throw new NinVerificationException(
                'Identity verification is not available right now. Please contact support.'
            );
        }

        $baseUrl = $sandbox ? 'https://sandbox.dojah.io' : 'https://api.dojah.io';

        try {
            $response = Http::withHeaders([
                'Authorization' => $secret,
                'AppId' => $appId,
            ])->timeout(15)->get($baseUrl.'/api/v1/kyc/nin', ['nin' => $nin]);
        } catch (ConnectionException) {
            throw new NinVerificationException(
                'We could not reach the identity service. Please try again.'
            );
        }

        $dateOfBirth = $response->json('entity.date_of_birth');

        if ($response->failed() || empty($dateOfBirth)) {
            Log::warning('NIN verification rejected.', [
                'status' => $response->status(),
                'nin' => $nin,
            ]);

            throw new NinVerificationException(match ($response->status()) {
                404 => 'We could not find that NIN. Check the number and try again.',
                402 => 'Identity verification is unavailable. Please contact support.',
                400 => 'That NIN is not valid. Check the number and try again.',
                default => 'We could not verify your identity right now. Please try again shortly.',
            });
        }

        return [
            'date_of_birth' => Carbon::parse($dateOfBirth)->toDateString(),
            'first_name' => $response->json('entity.first_name'),
            'last_name' => $response->json('entity.last_name'),
            'gender' => $response->json('entity.gender'),
        ];
    }
}
