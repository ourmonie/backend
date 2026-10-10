<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\NinVerification;
use App\Services\NinVerificationException;
use App\Support\EmailTemplates;
use App\Support\MailSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AuthController extends Controller
{
    public function __construct(private readonly AccountProvisioner $provisioner)
    {
    }

    /**
     * Apply to join, then create the permanent member account structure.
     *
     * Which gates apply is decided by admin settings (seamless mode can turn
     * referral and identity checks off) - never by the .env file.
     */
    public function register(Request $request): JsonResponse
    {
        $requireReferral = RegistrationController::requireReferral();
        $requireNin = RegistrationController::requireNin();
        $domains = RegistrationController::allowedEmailDomains();

        // Members type addresses however they like - normalise before the
        // rules run so the account always stores a lowercased email (15).
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:190', 'unique:users,email',
                function (string $attribute, mixed $value, callable $fail) use ($domains) {
                    $domain = strtolower(substr(strrchr((string) $value, '@') ?: '', 1));

                    if (! in_array($domain, $domains, true)) {
                        $friendly = array_map(
                            fn (string $host) => match (true) {
                                str_contains($host, 'gmail') => 'Gmail',
                                str_contains($host, 'yahoo') => 'Yahoo',
                                str_contains($host, 'hotmail') => 'Hotmail',
                                default => $host,
                            },
                            $domains
                        );

                        $fail('Use a '.implode(' or ', $friendly).' address to join. Other email providers are not accepted.');
                    }
                },
            ],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'whatsapp' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s()-]{10,20}$/', 'unique:users,whatsapp'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'referral_code' => [$requireReferral ? 'required' : 'nullable', 'string', 'max:20'],
            // With live NIN verification the date of birth comes from NIMC,
            // so the form does not ask for it; manual mode still does.
            'date_of_birth' => [$requireNin && ! NinVerification::isLive() ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'nin' => [$requireNin ? 'required' : 'nullable', 'digits:11', 'unique:users,nin'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ], self::JOIN_MESSAGES);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();

        ['date_of_birth' => $dateOfBirth, 'verified' => $verified] = $this->assertAdult($data, $requireNin);
        $code = strtoupper(trim((string) ($data['referral_code'] ?? '')));
        $referrer = $this->findReferrer($data['referral_code'] ?? null);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'],
            'nin' => $data['nin'] ?? null,
            'date_of_birth' => $dateOfBirth,
            'nin_verified_at' => $verified ? now() : null,
            'password' => $data['password'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $member = $this->provisionMembership($user, $data, $referrer, $code);

        $user->update(['last_login_at' => now()]);

        $this->sendWelcomeEmail($user, $member);

        return response()->json($this->sessionPayload($user), 201);
    }

    /* --------------------------- Google sign-in --------------------------- */

    /** Send the browser to Google's consent screen. */
    public function googleRedirect(): RedirectResponse
    {
        if (! $this->googleEnabled() || $this->googleClientId() === '') {
            return redirect()->away($this->frontendUrl().'/login?google_error=unavailable');
        }

        $state = Str::random(40);
        Cache::put("auth.google.state.{$state}", 1, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => $this->googleClientId(),
            'redirect_uri' => route('auth.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    /**
     * Google sends the browser back here. An existing member signs in
     * through a one-time code; a new applicant carries their Google profile
     * into the join flow, where the referral and identity gates still apply.
     */
    public function googleCallback(Request $request): RedirectResponse
    {
        $frontend = $this->frontendUrl();

        if ((string) $request->query('error', '') === 'access_denied') {
            return redirect()->away($frontend.'/login?google_error=cancelled');
        }

        $state = (string) $request->query('state', '');

        if ($state === '' || ! Cache::pull("auth.google.state.{$state}")) {
            return redirect()->away($frontend.'/login?google_error=expired');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->away($frontend.'/login?google_error=failed');
        }

        $profile = $this->googleUser($code);

        if ($profile === null) {
            return redirect()->away($frontend.'/login?google_error=failed');
        }

        $user = User::where('google_id', $profile['sub'])->orWhere('email', $profile['email'])->first();

        if ($user !== null) {
            $user->update([
                'google_id' => $profile['sub'],
                'avatar' => $user->avatar ?: $profile['picture'],
                'last_login_at' => now(),
            ]);

            $once = Str::random(40);
            Cache::put("auth.google.once.{$once}", $user->id, now()->addMinutes(5));

            return redirect()->away($frontend."/auth/google/callback?once={$once}");
        }

        // A brand-new applicant: hold the profile until the join flow finishes.
        $domains = RegistrationController::allowedEmailDomains();
        $domain = substr(strrchr($profile['email'], '@') ?: '', 1);

        if (! in_array($domain, $domains, true)) {
            return redirect()->away($frontend.'/register?google_error=domain');
        }

        $pending = Str::random(40);
        Cache::put("auth.google.pending.{$pending}", $profile, now()->addMinutes(15));

        return redirect()->away($frontend."/register?pending={$pending}");
    }

    /** Exchange the one-time code from googleCallback for a session. */
    public function googleComplete(Request $request): JsonResponse
    {
        $once = (string) $request->input('once', '');
        $userId = $once === '' ? null : Cache::pull("auth.google.once.{$once}");

        if ($userId === null) {
            throw ValidationException::withMessages([
                'once' => ['Your Google sign-in has expired. Please try again.'],
            ]);
        }

        $user = User::find($userId);

        if ($user === null || ! $user->is_active) {
            throw ValidationException::withMessages([
                'once' => ['This account is inactive. Please contact support.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json($this->sessionPayload($user));
    }

    /** The Google profile waiting in the join flow - does not consume it. */
    public function googlePendingProfile(Request $request): JsonResponse
    {
        $pending = (string) $request->input('pending', '');
        $profile = $pending === '' ? null : Cache::get("auth.google.pending.{$pending}");

        if ($profile === null) {
            throw ValidationException::withMessages([
                'pending' => ['This Google sign-in has expired. Please start again.'],
            ]);
        }

        return response()->json([
            'name' => (string) ($profile['name'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'picture' => $profile['picture'] ?? null,
        ]);
    }

    /**
     * Finish joining with a Google profile. Referral, identity and age gates
     * apply exactly as they do on the regular join form - signing up with
     * Google never skips a membership requirement.
     */
    public function registerWithGoogle(Request $request): JsonResponse
    {
        $requireReferral = RegistrationController::requireReferral();
        $requireNin = RegistrationController::requireNin();
        $domains = RegistrationController::allowedEmailDomains();

        $pending = (string) $request->input('pending', '');
        $profile = $pending === '' ? null : Cache::get("auth.google.pending.{$pending}");

        if ($profile === null) {
            throw ValidationException::withMessages([
                'pending' => ['This Google sign-in has expired. Please start again.'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'whatsapp' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s()-]{10,20}$/', 'unique:users,whatsapp'],
            'referral_code' => [$requireReferral ? 'required' : 'nullable', 'string', 'max:20'],
            'date_of_birth' => [$requireNin && ! NinVerification::isLive() ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'nin' => [$requireNin ? 'required' : 'nullable', 'digits:11', 'unique:users,nin'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ], self::JOIN_MESSAGES);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $email = strtolower((string) $profile['email']);
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if (! in_array($domain, $domains, true)) {
            throw ValidationException::withMessages([
                'pending' => ['Use a Gmail, Yahoo or Hotmail Google account to join. Other email providers are not accepted.'],
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'pending' => ['An account already exists for this email. Sign in instead.'],
            ]);
        }

        ['date_of_birth' => $dateOfBirth, 'verified' => $verified] = $this->assertAdult($data, $requireNin);
        $code = strtoupper(trim((string) ($data['referral_code'] ?? '')));
        $referrer = $this->findReferrer($data['referral_code'] ?? null);

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'],
            'nin' => $data['nin'] ?? null,
            'date_of_birth' => $dateOfBirth,
            'nin_verified_at' => $verified ? now() : null,
            'google_id' => (string) $profile['sub'],
            'avatar' => $profile['picture'] ?? null,
            'password' => Str::random(60),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Cache::forget("auth.google.pending.{$pending}");

        $member = $this->provisionMembership($user, $data, $referrer, $code);

        $user->update(['last_login_at' => now()]);

        $this->sendWelcomeEmail($user, $member);

        return response()->json($this->sessionPayload($user), 201);
    }

    /**
     * Login with email or phone plus password.
     */
    public function login(Request $request): JsonResponse
    {
        // Same rule the rest of the world follows: trim it, and lowercase
        // the address half so case never decides who gets in (15).
        if (is_string($request->input('login'))) {
            $login = trim($request->input('login'));
            $request->merge(['login' => str_contains($login, '@') ? strtolower($login) : $login]);
        }

        $validator = Validator::make($request->all(), [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $credentials = $request->input('login');
        $isEmail = str_contains($credentials, '@');

        $user = User::where($isEmail ? 'email' : 'phone', $credentials)->first();

        if ($user === null || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Incorrect email or password. Check your details and try again.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['This account is inactive. Please contact support.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json($this->sessionPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'member.accounts', 'member.wallet.account', 'member.rewardAccount', 'member.tier',
        ]);

        return response()->json([
            'user' => $this->userPayload($user),
            'member' => $user->member,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * The guided welcome screens (requirement 16) are mandatory: the member
     * walks through all four and this stamps the account as onboarded so the
     * app stops intercepting them. Idempotent - a reload cannot re-flag.
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->onboarded_at === null) {
            $user->update(['onboarded_at' => now()]);
        }

        return response()->json([
            'message' => 'Welcome to the cooperative.',
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Set or change the 4-6 digit transaction PIN.
     */
    public function setTransactionPin(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => ['required', 'string', 'digits:4,6'],
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => ['Your password is incorrect.']]);
        }

        $user->update(['transaction_pin' => bcrypt($request->input('pin'))]);

        return response()->json([
            'message' => 'Transaction PIN set.',
            'has_pin' => true,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'password.regex' => 'Password must be at least 8 characters and include both letters and numbers.',
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }

        $user->update([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    /* --------------------------- Password reset --------------------------- */

    /**
     * Send a reset link. The answer is identical whether or not the address
     * is registered, so this endpoint cannot be used to probe accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        if (! MailSettings::enabled()) {
            throw ValidationException::withMessages([
                'email' => 'Password reset is unavailable right now. Please contact support.',
            ]);
        }

        $email = strtolower((string) $request->input('email'));
        $user = User::where('email', $email)->first();

        if ($user !== null) {
            $token = Str::random(60);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            try {
                MailSettings::send(
                    $user->email,
                    'Reset your password on OurMonie',
                    EmailTemplates::resetPassword($user->name, $token, $user->email)
                );
            } catch (\Throwable $exception) {
                Log::error('Password reset email failed to send.', ['error' => $exception->getMessage(), 'email' => $email]);

                throw ValidationException::withMessages([
                    'email' => 'We could not send the reset email right now. Please try again shortly.',
                ]);
            }
        }

        return response()->json([
            'message' => 'If that email address is registered, a password reset link is on its way.',
        ]);
    }

    /** Consume a reset token: set the new password and sign out every device. */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'token' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)/', 'confirmed'],
        ], [
            'password.regex' => 'Password must be at least 8 characters and include both letters and numbers.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $email = strtolower((string) $request->input('email'));
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $record !== null
            && Hash::check((string) $request->input('token'), (string) $record->token)
            && Carbon::parse($record->created_at)->greaterThan(now()->subHour());

        $user = $valid ? User::where('email', $email)->first() : null;

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['This reset link is invalid or has expired. Request a new one.'],
            ]);
        }

        $user->update([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
        ]);

        // Password changed: no session anywhere may outlive it.
        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Your password has been updated. Sign in with your new password.',
        ]);
    }

    /**
     * Best-effort welcome email: a mail outage must never roll back a
     * successful membership (requirement 5).
     */
    private function sendWelcomeEmail(User $user, Member $member): void
    {
        if (! MailSettings::enabled()) {
            return;
        }

        try {
            MailSettings::send(
                $user->email,
                'Welcome to OurMonie Digital Cooperative',
                EmailTemplates::welcome($user->name, (string) $member->member_number, (string) $member->referral_code)
            );
        } catch (\Throwable $exception) {
            Log::error('Welcome email failed to send.', [
                'error' => $exception->getMessage(),
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Messages shared by both join paths (form and Google) so an applicant
     * sees the same professional wording however they arrive.
     */
    private const JOIN_MESSAGES = [
        'referral_code.required' => 'A referral code from an existing member is required to join.',
        'password.regex' => 'Password must be at least 8 characters and include both letters and numbers.',
        'whatsapp.required' => 'Enter the WhatsApp number we should reach you on.',
        'whatsapp.regex' => 'Enter a valid WhatsApp number.',
        'nin.required' => 'Enter your National Identification Number (NIN).',
        'nin.digits' => 'Your NIN must be exactly 11 digits.',
        'date_of_birth.required' => 'Enter your date of birth.',
        'date_of_birth.before_or_equal' => 'That date of birth is in the future.',
    ];

    /**
     * Enforce the 18+ gate. Live NIN verification reads the date of birth
     * from NIMC; manual mode trusts the declared one.
     *
     * @return array{date_of_birth: ?string, verified: bool} the date to store
     */
    private function assertAdult(array $data, bool $requireNin): array
    {
        $minAge = RegistrationController::minAge();
        $verifyNin = $requireNin && NinVerification::isLive();
        $dateOfBirth = $data['date_of_birth'] ?? null;

        if ($verifyNin) {
            try {
                $record = app(NinVerification::class)->verify((string) $data['nin']);
            } catch (NinVerificationException $exception) {
                throw ValidationException::withMessages(['nin' => $exception->getMessage()]);
            }

            $dateOfBirth = $record['date_of_birth'];

            if (Carbon::parse($dateOfBirth)->age < $minAge) {
                throw ValidationException::withMessages([
                    'nin' => "Members must be at least {$minAge} years old. This NIN belongs to someone under {$minAge}.",
                ]);
            }
        } elseif (! empty($dateOfBirth) && Carbon::parse($dateOfBirth)->age < $minAge) {
            throw ValidationException::withMessages([
                'date_of_birth' => "You must be at least {$minAge} years old to join the cooperative.",
            ]);
        }

        return ['date_of_birth' => $dateOfBirth, 'verified' => $verifyNin];
    }

    /** The referral must belong to a real member; empty means self-referral off. */
    private function findReferrer(mixed $code): ?Member
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        $referrer = Member::where('referral_code', $code)->first();

        if ($referrer === null) {
            throw ValidationException::withMessages([
                'referral_code' => 'We could not find that referral code. Check the code with the member who invited you.',
            ]);
        }

        return $referrer;
    }

    /** Member record, permanent accounts and the referral link. */
    private function provisionMembership(User $user, array $data, ?Member $referrer, string $code): Member
    {
        $member = Member::create([
            'user_id' => $user->id,
            'member_number' => AccountProvisioner::nextMemberNumber($user->id),
            'referral_code' => AccountProvisioner::generateReferralCode($data['name']),
            'membership_status' => 'pending',
            'kyc_status' => 'not_started',
            'member_since' => now(),
            'occupation' => $data['occupation'] ?? null,
            'employer' => $data['employer'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'employment_status' => $data['employment_status'] ?? null,
            'referred_by' => $referrer?->id,
        ]);

        // Permanent wallet, savings accounts and reward account.
        $member = $this->provisioner->provisionFor($member);

        if ($referrer !== null) {
            Referral::create([
                'referrer_member_id' => $referrer->id,
                'referee_user_id' => $user->id,
                'referee_member_id' => $member->id,
                'code' => $code,
                'status' => Referral::STATUS_REGISTERED,
                'registered_at' => now(),
            ]);
        }

        return $member;
    }

    /** The one response shape every sign-in path returns. */
    private function sessionPayload(User $user): array
    {
        return [
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $this->userPayload($user),
            'member' => $user->member()->with(['accounts', 'wallet.account', 'rewardAccount', 'tier'])->first(),
        ];
    }

    private function googleEnabled(): bool
    {
        return (bool) Setting::getValue('auth.google_enabled', false);
    }

    private function googleClientId(): string
    {
        return trim((string) Setting::getValue('auth.google_client_id', ''));
    }

    /**
     * The deployed React client, resolved from the CORS origin list so this
     * works whether config is cached or not.
     */
    private function frontendUrl(): string
    {
        $origins = config('cors.allowed_origins');
        $origin = is_array($origins) && $origins !== [] ? (string) reset($origins) : 'http://localhost:5173';

        return rtrim($origin, '/');
    }

    /**
     * Trade the consent code for the Google profile. Returns null on any
     * failure - the caller turns that into a clean redirect for the browser.
     *
     * @return ?array{sub: string, email: string, name: string, picture: ?string}
     */
    private function googleUser(string $code): ?array
    {
        try {
            $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->googleClientId(),
                'client_secret' => trim((string) Setting::getValue('auth.google_client_secret', '')),
                'redirect_uri' => route('auth.google.callback'),
                'grant_type' => 'authorization_code',
            ]);

            $accessToken = $token->json('access_token');

            if ($token->failed() || empty($accessToken)) {
                return null;
            }

            $userinfo = Http::timeout(15)->withToken($accessToken)
                ->get('https://openidconnect.googleapis.com/v1/userinfo');

            $email = strtolower((string) $userinfo->json('email'));

            if ($userinfo->failed() || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            return [
                'sub' => (string) $userinfo->json('sub'),
                'email' => $email,
                'name' => (string) ($userinfo->json('name') ?: $email),
                'picture' => $userinfo->json('picture'),
            ];
        } catch (ConnectionException) {
            return null;
        }
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'is_admin' => $user->is_admin,
            'roles' => $user->roleCodes(),
            'permissions' => $user->permissionCodes(),
            'has_transaction_pin' => $user->transaction_pin !== null,
            'onboarded' => $user->onboarded_at !== null,
        ];
    }
}
