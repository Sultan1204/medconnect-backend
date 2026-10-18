<?php

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\DeviceController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Models\AccessToken;
use Illuminate\Support\Facades\Route;

// Public authentication endpoints (strictly rate limited).
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('password/reset', [PasswordController::class, 'reset']);
    });

    Route::middleware('throttle:otp')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('otp/resend', [AuthController::class, 'resendOtp']);
        Route::post('password/forgot', [PasswordController::class, 'forgot']);
    });

    // Restricted tokens: MFA enrolment / challenge.
    Route::middleware(['auth:sanctum', 'account.active', 'throttle:auth'])->prefix('mfa')->group(function () {
        Route::post('setup', [MfaController::class, 'setup'])
            ->middleware('ability:'.AccessToken::ABILITY_MFA_ENROLL.','.AccessToken::ABILITY_FULL);
        Route::post('confirm', [MfaController::class, 'confirm'])
            ->middleware('ability:'.AccessToken::ABILITY_MFA_ENROLL.','.AccessToken::ABILITY_FULL);
        Route::post('challenge', [MfaController::class, 'challenge'])
            ->middleware('ability:'.AccessToken::ABILITY_MFA_CHALLENGE);
    });
});

// Fully authenticated account endpoints.
Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::post('password/change', [PasswordController::class, 'change'])->middleware('throttle:auth');
        Route::get('devices', [DeviceController::class, 'index']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereUuid('device');
        Route::post('mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:auth');
        Route::delete('mfa', [MfaController::class, 'disable'])->middleware('throttle:auth');
    });

    Route::get('me', [AccountController::class, 'show']);
    Route::patch('me', [AccountController::class, 'update']);
    Route::post('me/email/verification-notification', [AccountController::class, 'sendEmailVerification'])->middleware('throttle:otp');
    Route::post('me/email/verify', [AccountController::class, 'verifyEmail'])->middleware('throttle:auth');
    Route::post('me/deactivate', [AccountController::class, 'deactivate'])->middleware('throttle:auth');
});
