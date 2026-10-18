<?php

use App\Modules\Doctors\Http\Controllers\Admin\DoctorAdminController;
use App\Modules\Doctors\Http\Controllers\CredentialController;
use App\Modules\Doctors\Http\Controllers\DoctorDirectoryController;
use App\Modules\Doctors\Http\Controllers\DoctorProfileController;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use Illuminate\Support\Facades\Route;

// Doctor's own profile (registered before the public {doctor} route).
Route::middleware(['auth:sanctum', 'account.active', 'token.full', 'role:'.RoleName::Doctor->value])
    ->prefix('doctors/me')
    ->group(function () {
        Route::get('/', [DoctorProfileController::class, 'show']);
        Route::patch('/', [DoctorProfileController::class, 'update']);
        Route::post('verification', [DoctorProfileController::class, 'submit']);
        Route::get('verification', [DoctorProfileController::class, 'history']);
        Route::get('credentials', [CredentialController::class, 'index']);
        Route::post('credentials', [CredentialController::class, 'store']);
        Route::get('credentials/{credential}/download', [CredentialController::class, 'download'])->whereUuid('credential');
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy'])->whereUuid('credential');
    });

// Public directory.
Route::get('doctors', [DoctorDirectoryController::class, 'index']);
Route::get('doctors/{doctor}', [DoctorDirectoryController::class, 'show'])->whereUuid('doctor');

// Administrative verification.
Route::middleware(['auth:sanctum', 'account.active', 'token.full', 'permission:'.Permission::DoctorsVerify->value])
    ->prefix('admin/doctors')
    ->group(function () {
        Route::get('pending', [DoctorAdminController::class, 'pending']);
        Route::get('/', [DoctorAdminController::class, 'index']);

        Route::whereUuid(['doctor', 'credential'])->group(function () {
            Route::get('{doctor}', [DoctorAdminController::class, 'show']);
            Route::get('{doctor}/credentials/{credential}/download', [DoctorAdminController::class, 'downloadCredential']);

            Route::middleware('mfa.step-up')->group(function () {
                Route::post('{doctor}/verify', [DoctorAdminController::class, 'verify']);
                Route::post('{doctor}/suspend', [DoctorAdminController::class, 'suspend']);
                Route::post('{doctor}/reinstate', [DoctorAdminController::class, 'reinstate']);
            });
        });
    });
