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
     * Register, then create the permanent member account structure.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'referral_code' => ['nullable', 'string', 'max:20'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $referrer = null;
        if (! empty($data['referral_code'])) {
            $referrer = Member::where('referral_code', strtoupper($data['referral_code']))->first();
        }

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
                'code' => $data['referral_code'],
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
