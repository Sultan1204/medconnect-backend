<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Pharmacies\Http\Controllers\Admin\PharmacyAdminController;
use App\Modules\Pharmacies\Http\Controllers\PatientDispenseController;
use App\Modules\Pharmacies\Http\Controllers\PharmacyPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    // Patient side.
    Route::middleware('role:'.RoleName::Patient->value)->group(function () {
        Route::get('pharmacies/branches', [PatientDispenseController::class, 'branches']);
        Route::post('prescriptions/{prescription}/dispense-requests', [PatientDispenseController::class, 'store'])->whereUuid('prescription');
        Route::get('prescriptions/{prescription}/dispense-requests', [PatientDispenseController::class, 'index'])->whereUuid('prescription');
        Route::get('dispense-requests/{dispenseRequest}', [PatientDispenseController::class, 'show'])->whereUuid('dispenseRequest');
        Route::post('dispense-requests/{dispenseRequest}/cancel', [PatientDispenseController::class, 'cancel'])->whereUuid('dispenseRequest');
    });

    // Pharmacy staff portal.
    Route::middleware('role:'.RoleName::PharmacyStaff->value)->prefix('pharmacy')->group(function () {
        Route::get('dispense-requests', [PharmacyPortalController::class, 'index']);
        Route::whereUuid('dispenseRequest')->group(function () {
            Route::get('dispense-requests/{dispenseRequest}', [PharmacyPortalController::class, 'show']);
            Route::post('dispense-requests/{dispenseRequest}/status', [PharmacyPortalController::class, 'updateStatus']);
            Route::get('dispense-requests/{dispenseRequest}/verify', [PharmacyPortalController::class, 'verify']);
        });
        Route::get('branches', [PharmacyPortalController::class, 'branches']);
        Route::patch('branches/{branch}', [PharmacyPortalController::class, 'updateBranch'])->whereUuid('branch');
        Route::get('reports/summary', [PharmacyPortalController::class, 'report']);
    });

    // Administration.
    Route::middleware('permission:'.Permission::PharmaciesVerify->value)->prefix('admin/pharmacies')->group(function () {
        Route::get('/', [PharmacyAdminController::class, 'index']);
        Route::post('/', [PharmacyAdminController::class, 'store']);
        Route::whereUuid('pharmacy')->group(function () {
            Route::get('{pharmacy}', [PharmacyAdminController::class, 'show']);
            Route::post('{pharmacy}/verify', [PharmacyAdminController::class, 'verify'])->middleware('mfa.step-up');
            Route::post('{pharmacy}/branches', [PharmacyAdminController::class, 'storeBranch']);
            Route::post('{pharmacy}/staff', [PharmacyAdminController::class, 'storeStaff']);
        });
    });
});
