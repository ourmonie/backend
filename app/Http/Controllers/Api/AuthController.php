<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Referral;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        $minAge = RegistrationController::minAge();
        $domains = RegistrationController::allowedEmailDomains();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'lowercase', 'email:rfc', 'max:190', 'unique:users,email',
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
            'date_of_birth' => [$requireNin ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'nin' => [$requireNin ? 'required' : 'nullable', 'digits:11', 'unique:users,nin'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ], [
            'referral_code.required' => 'A referral code from an existing member is required to join.',
            'password.regex' => 'Password must be at least 8 characters and include both letters and numbers.',
            'whatsapp.required' => 'Enter the WhatsApp number we should reach you on.',
            'whatsapp.regex' => 'Enter a valid WhatsApp number.',
            'nin.required' => 'Enter your National Identification Number (NIN).',
            'nin.digits' => 'Your NIN must be exactly 11 digits.',
            'date_of_birth.required' => 'Enter your date of birth.',
            'date_of_birth.before_or_equal' => 'That date of birth is in the future.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();

        // Age gate: adult membership only.
        if (! empty($data['date_of_birth'])) {
            $age = \Illuminate\Support\Carbon::parse($data['date_of_birth'])->age;

            if ($age < $minAge) {
                throw ValidationException::withMessages([
                    'date_of_birth' => "You must be at least {$minAge} years old to join the cooperative.",
                ]);
            }
        }

        // The referral must belong to a real member when the gate is on.
        $referrer = null;
        $code = strtoupper(trim((string) ($data['referral_code'] ?? '')));

        if ($code !== '') {
            $referrer = Member::where('referral_code', $code)->first();

            if ($referrer === null) {
                throw ValidationException::withMessages([
                    'referral_code' => 'We could not find that referral code. Check the code with the member who invited you.',
                ]);
            }
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'],
            'nin' => $data['nin'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

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

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $this->userPayload($user->fresh()),
            'member' => $member,
        ], 201);
    }

    /**
     * Login with email or phone plus password.
     */
    public function login(Request $request): JsonResponse
    {
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
                'login' => ['The provided credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['This account is inactive. Please contact support.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $this->userPayload($user),
            'member' => $user->member()->with(['accounts', 'wallet.account', 'rewardAccount', 'tier'])->first(),
        ]);
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
            throw ValidationException::withMessages(['password' => ['Password is incorrect.']]);
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        $user->update([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
        ]);

        return response()->json(['message' => 'Password updated.']);
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
        ];
    }
}
