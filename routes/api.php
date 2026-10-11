<?php

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminKycController;
use App\Http\Controllers\Api\Admin\AdminMemberController;
use App\Http\Controllers\Api\Admin\AdminPaymentGatewayController;
use App\Http\Controllers\Api\Admin\AdminRoleController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MemberDirectoryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Stateless Sanctum-authenticated API. The React client is the only consumer
| today; a future Flutter app consumes these exact same endpoints.
|
*/

Route::prefix('v1')->group(function () {

    /* ------------------------------ Public ------------------------------- */

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    /* Password recovery, delivered over the dashboard-configured SMTP. */
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    /* Opened from an inbox, usually on another device: the mailed token is
       the proof, so this one takes no session. */
    Route::post('/auth/email/change/confirm', [AuthController::class, 'confirmEmailChange'])
        ->middleware('throttle:10,1');

    /* Google sign-in: consent redirect, return leg, and the two hand-offs. */
    Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])
        ->name('auth.google.callback');
    Route::post('/auth/google/complete', [AuthController::class, 'googleComplete']);
    Route::post('/auth/google/profile', [AuthController::class, 'googlePendingProfile']);
    Route::post('/auth/register/google', [AuthController::class, 'registerWithGoogle']);

    /* Join policy the sign-up screen adapts to, and referral lookup. */
    Route::get('/registration/settings', [RegistrationController::class, 'settings']);
    Route::post('/registration/referral/validate', [RegistrationController::class, 'validateReferral']);

    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'ourmonie-api',
        'time' => now()->toIso8601String(),
    ]));

    /* --------------------------- Provider rails --------------------------- */

    /* Public by necessity: the providers call these with no session of ours.
       Authenticity is the signature, and every handler verifies it before it
       trusts a byte of the body. No throttle - providers retry on non-200
       and a dropped retry means a member's money never lands. */
    Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'paystack'])
        ->name('webhooks.paystack');
    Route::post('/webhooks/flutterwave', [PaymentWebhookController::class, 'flutterwave'])
        ->name('webhooks.flutterwave');

    /* Public so the branding the dashboard edits reaches an unauthenticated
       visitor. Design tokens only - never a credential. */
    Route::get('/public/settings', [\App\Http\Controllers\Api\PublicSettingsController::class, 'index']);

    /* --------------------------- Authenticated --------------------------- */

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/onboarding/complete', [AuthController::class, 'completeOnboarding']);
        Route::post('/auth/passport-photo', [AuthController::class, 'uploadPassportPhoto']);
        Route::get('/auth/passport-photo', [AuthController::class, 'showPassportPhoto']);
        Route::put('/auth/pin', [AuthController::class, 'setTransactionPin']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);

        /* Profile edits: name and phone outright, email after a mailed link. */
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/email/change', [AuthController::class, 'requestEmailChange'])
            ->middleware('throttle:5,1');
        Route::post('/auth/email/change/cancel', [AuthController::class, 'cancelEmailChange']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/chart', [DashboardController::class, 'chart']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::post('/wallet/funding/status', [WalletController::class, 'fundingStatus']);
        Route::post('/wallet/allocate', [WalletController::class, 'allocate']);
        Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{reference}', [TransactionController::class, 'show']);

        Route::get('/savings', [SavingsController::class, 'index']);
        Route::get('/savings/products', [SavingsController::class, 'products']);
        Route::get('/savings/goals', [SavingsController::class, 'goals']);
        Route::post('/savings/goals', [SavingsController::class, 'storeGoal']);
        Route::get('/savings/vaults', [SavingsController::class, 'vaults']);
        Route::post('/savings/deposits', [SavingsController::class, 'deposit']);

        Route::get('/rewards', [RewardController::class, 'index']);
        Route::get('/rewards/history', [RewardController::class, 'history']);
        Route::post('/rewards/convert', [RewardController::class, 'convert']);

        Route::get('/referrals', [ReferralController::class, 'index']);

        /* The cooperative directory - name, number and face, nothing else. */
        Route::get('/members', [MemberDirectoryController::class, 'index']);
        Route::get('/members/{id}/photo', [MemberDirectoryController::class, 'photo']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read', [NotificationController::class, 'markRead']);
        Route::get('/announcements', [AnnouncementController::class, 'index']);

        /* ------------------------- Member KYC (18) ------------------------- */

        Route::get('/kyc', [KycController::class, 'show']);
        Route::post('/kyc', [KycController::class, 'store']);

        /* --------------------- Admin portal (18, 20, 31) ------------------- */

        Route::prefix('admin')->middleware('admin')->group(function () {

            Route::get('/overview', [AdminDashboardController::class, 'overview'])
                ->middleware('permission:dashboard.view');

            Route::get('/members', [AdminMemberController::class, 'index'])
                ->middleware('permission:member.view');
            Route::get('/members/{id}', [AdminMemberController::class, 'show'])
                ->middleware('permission:member.view');
            Route::put('/members/{id}', [AdminMemberController::class, 'update'])
                ->middleware('permission:member.manage');
            Route::put('/members/{id}/status', [AdminMemberController::class, 'updateStatus'])
                ->middleware('permission:member.manage');
            Route::post('/members/bulk-delete', [AdminMemberController::class, 'bulkDestroy'])
                ->middleware('permission:member.manage');
            Route::delete('/members/{id}', [AdminMemberController::class, 'destroy'])
                ->middleware('permission:member.manage');
            Route::post('/members/{id}/sweep', [AdminMemberController::class, 'sweepBalance'])
                ->middleware('permission:member.manage');
            Route::post('/members/{id}/holds', [AdminMemberController::class, 'storeHold'])
                ->middleware('permission:wallet.hold');
            Route::delete('/members/{id}/holds/{holdId}', [AdminMemberController::class, 'releaseHold'])
                ->middleware('permission:wallet.hold');

            Route::get('/kyc', [AdminKycController::class, 'index'])
                ->middleware('permission:kyc.view');
            Route::get('/kyc/{record}/documents/{index}', [AdminKycController::class, 'document'])
                ->middleware('permission:kyc.view')
                ->name('admin.kyc.document');
            Route::get('/kyc/{record}', [AdminKycController::class, 'show'])
                ->middleware('permission:kyc.view');
            Route::post('/kyc/{record}/decision', [AdminKycController::class, 'decide'])
                ->middleware('permission:kyc.review');

            Route::get('/roles', [AdminRoleController::class, 'index'])
                ->middleware('permission:role.manage');
            Route::get('/staff', [AdminRoleController::class, 'staff'])
                ->middleware('permission:role.manage');
            Route::put('/roles/{id}/permissions', [AdminRoleController::class, 'updatePermissions'])
                ->middleware('permission:role.manage');
            Route::put('/users/{userId}/roles', [AdminRoleController::class, 'assignUserRoles'])
                ->middleware('permission:role.manage');

            /* Dashboard-editable policy: registration gates and identity checks. */
            Route::get('/settings/{group}', [AdminSettingsController::class, 'show'])
                ->middleware('permission:settings.view');
            Route::put('/settings/{group}', [AdminSettingsController::class, 'update'])
                ->middleware('permission:settings.general');
            Route::post('/settings/mail/test', [AdminSettingsController::class, 'test'])
                ->middleware('permission:settings.general');

            /* PRD 21 - the payment rails: readiness, key verification and
               issuing a member their dedicated account number. */
            Route::get('/payments', [AdminPaymentGatewayController::class, 'index'])
                ->middleware('permission:settings.view');
            Route::post('/payments/test', [AdminPaymentGatewayController::class, 'test'])
                ->middleware('permission:settings.general');
            Route::post('/payments/members/{id}/assign-account', [AdminPaymentGatewayController::class, 'assignAccount'])
                ->middleware('permission:member.manage');
        });
    });
});
