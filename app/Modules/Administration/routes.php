<?php

use App\Modules\Administration\Http\Controllers\Admin\AdministratorController;
use App\Modules\Administration\Http\Controllers\Admin\AuditLogController;
use App\Modules\Administration\Http\Controllers\Admin\ComplaintAdminController;
use App\Modules\Administration\Http\Controllers\Admin\DataRequestAdminController;
use App\Modules\Administration\Http\Controllers\Admin\ReportController;
use App\Modules\Administration\Http\Controllers\Admin\SettingsController;
use App\Modules\Administration\Http\Controllers\Admin\UserAdminController;
use App\Modules\Administration\Http\Controllers\ComplaintController;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::middleware('role:'.implode('|', [RoleName::Patient->value, RoleName::Doctor->value, RoleName::PharmacyStaff->value]))->group(function () {
        Route::post('complaints', [ComplaintController::class, 'store'])->middleware('throttle:10,1');
        Route::get('complaints', [ComplaintController::class, 'index']);
        Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->whereUuid('complaint');
    });

    Route::prefix('admin')->group(function () {
        Route::middleware('permission:'.Permission::ComplaintsManage->value)->group(function () {
            Route::get('complaints', [ComplaintAdminController::class, 'index']);
            Route::get('complaints/{complaint}', [ComplaintAdminController::class, 'show'])->whereUuid('complaint');
            Route::patch('complaints/{complaint}', [ComplaintAdminController::class, 'update'])->whereUuid('complaint');
        });

        Route::middleware('permission:'.Permission::UsersView->value)->group(function () {
            Route::get('users', [UserAdminController::class, 'index']);
            Route::get('users/{user}', [UserAdminController::class, 'show'])->whereUuid('user');
        });
        Route::middleware(['permission:'.Permission::UsersManage->value, 'mfa.step-up'])->group(function () {
            Route::post('users/{user}/suspend', [UserAdminController::class, 'suspend'])->whereUuid('user');
            Route::post('users/{user}/reactivate', [UserAdminController::class, 'reactivate'])->whereUuid('user');
        });

        Route::middleware('permission:'.Permission::DataRequestsManage->value)->group(function () {
            Route::get('data-requests', [DataRequestAdminController::class, 'index']);
            Route::get('data-requests/{dataRequest}', [DataRequestAdminController::class, 'show'])->whereUuid('dataRequest');
            Route::post('data-requests/{dataRequest}/handle', [DataRequestAdminController::class, 'handle'])->whereUuid('dataRequest')->middleware('mfa.step-up');
        });

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:'.Permission::AuditView->value);

        Route::middleware('permission:'.Permission::ReportsView->value)->prefix('reports')->group(function () {
            Route::get('overview', [ReportController::class, 'overview']);
            Route::get('appointments', [ReportController::class, 'appointments']);
            Route::get('financial', [ReportController::class, 'financial']);
        });

        Route::middleware('permission:'.Permission::SettingsManage->value)->group(function () {
            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings/{key}', [SettingsController::class, 'update'])->middleware('mfa.step-up');
            Route::delete('settings/{key}', [SettingsController::class, 'destroy'])->middleware('mfa.step-up');
        });

        Route::middleware('permission:'.Permission::AdminsManage->value)->group(function () {
            Route::get('permissions', [AdministratorController::class, 'permissions']);
            Route::get('administrators', [AdministratorController::class, 'index']);
            Route::put('administrators/{user}/permissions', [AdministratorController::class, 'syncPermissions'])->whereUuid('user')->middleware('mfa.step-up');
        });
    });
});
