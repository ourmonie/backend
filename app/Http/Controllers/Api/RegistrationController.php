<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Setting;
use App\Services\NinVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Everything the join screen needs before a member exists:
 * which requirements are switched on, and whether a referral code is real.
 *
 * Public by design - the join form has no session to protect - but it only
 * ever reveals policy flags and a masked referrer name.
 */
class RegistrationController extends Controller
{
    /** Policy the join form adapts to. */
    public function settings(): JsonResponse
    {
        return response()->json([
            'require_referral' => self::requireReferral(),
            'require_nin' => self::requireNin(),
            'nin_verification' => NinVerification::mode(),
            'google_enabled' => (bool) Setting::getValue('auth.google_enabled', false),
            'min_age' => (int) Setting::getValue('registration.min_age', 18),
            'allowed_email_domains' => Setting::getValue('registration.allowed_email_domains', ['gmail.com', 'yahoo.com', 'hotmail.com']),
        ]);
    }

    /**
     * Check a referral code. The caller needs the referrer's name on screen
     * before the form unlocks, so that is all this returns.
     */
    public function validateReferral(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:20'],
        ], [
            'code.required' => 'Enter the referral code from the member who invited you.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $code = strtoupper(trim($request->input('code')));

        $referrer = Member::with('user')
            ->where('referral_code', $code)
            ->first();

        if ($referrer === null) {
            throw ValidationException::withMessages([
                'code' => 'We could not find that referral code. Check the code with the member who invited you.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'code' => $code,
            'referrer_name' => self::maskName($referrer->user?->name ?? 'A member'),
        ]);
    }

    /* ------------------------------ Helpers ------------------------------ */

    public static function requireReferral(): bool
    {
        if (Setting::getValue('registration.seamless', false)) {
            return false;
        }

        return Setting::getValue('registration.require_referral', true);
    }

    public static function requireNin(): bool
    {
        if (Setting::getValue('registration.seamless', false)) {
            return false;
        }

        return Setting::getValue('registration.require_nin', true);
    }

    public static function allowedEmailDomains(): array
    {
        return Setting::getValue('registration.allowed_email_domains', ['gmail.com', 'yahoo.com', 'hotmail.com']);
    }

    public static function minAge(): int
    {
        return (int) Setting::getValue('registration.min_age', 18);
    }

    /** "Adebayo Okafor" -> "Adebayo O." - enough to recognise, not enough to harvest. */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = (string) array_shift($parts);

        if ($parts === []) {
            return $first;
        }

        $surname = (string) array_pop($parts);

        return $first.' '.mb_substr($surname, 0, 1).'.';
    }
}
