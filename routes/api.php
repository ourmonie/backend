<?php

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminKycController;
use App\Http\Controllers\Api\Admin\AdminMemberController;
use App\Http\Controllers\Api\Admin\AdminRoleController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\NotificationController;
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

    /* --------------------------- Authenticated --------------------------- */

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::put('/auth/pin', [AuthController::class, 'setTransactionPin']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/chart', [DashboardController::class, 'chart']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::post('/wallet/fund', [WalletController::class, 'fund']);
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
            Route::put('/members/{id}/status', [AdminMemberController::class, 'updateStatus'])
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
        });
    });
});
