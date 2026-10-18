<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Patients\Http\Controllers\Admin\GuardianLinkController;
use App\Modules\Patients\Http\Controllers\ConsentController;
use App\Modules\Patients\Http\Controllers\DataRequestController;
use App\Modules\Patients\Http\Controllers\PatientController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::middleware('role:'.RoleName::Patient->value)->prefix('patients')->group(function () {
        Route::get('me', [PatientController::class, 'me']);
        Route::patch('me', [PatientController::class, 'updateMe']);
        Route::get('me/dependents', [PatientController::class, 'dependents']);
        Route::post('me/dependents', [PatientController::class, 'storeDependent']);

        Route::whereUuid('patient')->group(function () {
            Route::get('{patient}', [PatientController::class, 'show']);
            Route::patch('{patient}', [PatientController::class, 'update']);
            Route::get('{patient}/consents', [ConsentController::class, 'index']);
            Route::post('{patient}/consents', [ConsentController::class, 'store']);
            Route::get('{patient}/data-requests', [DataRequestController::class, 'index']);
            Route::post('{patient}/data-requests', [DataRequestController::class, 'store']);
        });
    });

    Route::middleware('permission:'.Permission::UsersManage->value)->prefix('admin/guardian-links')->group(function () {
        Route::get('/', [GuardianLinkController::class, 'index']);
        Route::post('{link}/decision', [GuardianLinkController::class, 'decide'])->whereUuid('link');
    });
});
