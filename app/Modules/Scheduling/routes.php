<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Scheduling\Http\Controllers\Admin\AppointmentMonitorController;
use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\AvailabilityController;
use App\Modules\Scheduling\Http\Controllers\DoctorScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('doctors/{doctor}/availability', AvailabilityController::class)->whereUuid('doctor');

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::middleware('role:'.RoleName::Doctor->value)->prefix('doctors/me')->group(function () {
        Route::get('availability-rules', [DoctorScheduleController::class, 'rules']);
        Route::put('availability-rules', [DoctorScheduleController::class, 'replaceRules']);
        Route::get('availability-slots', [DoctorScheduleController::class, 'slots']);
        Route::post('availability-slots', [DoctorScheduleController::class, 'storeSlot']);
        Route::delete('availability-slots/{slot}', [DoctorScheduleController::class, 'destroySlot'])->whereUuid('slot');
        Route::get('schedule-settings', [DoctorScheduleController::class, 'settings']);
        Route::patch('schedule-settings', [DoctorScheduleController::class, 'updateSettings']);
    });

    Route::prefix('appointments')->group(function () {
        Route::get('/', [AppointmentController::class, 'index'])->middleware('role:'.RoleName::Patient->value.'|'.RoleName::Doctor->value);
        Route::post('/', [AppointmentController::class, 'store'])->middleware(['role:'.RoleName::Patient->value, 'throttle:booking']);

        Route::whereUuid('appointment')->group(function () {
            Route::get('{appointment}', [AppointmentController::class, 'show']);
            Route::post('{appointment}/cancel', [AppointmentController::class, 'cancel']);
            Route::post('{appointment}/accept', [AppointmentController::class, 'accept']);
            Route::post('{appointment}/decline', [AppointmentController::class, 'decline']);
            Route::post('{appointment}/no-show', [AppointmentController::class, 'noShow']);
        });
    });

    Route::get('admin/appointments', [AppointmentMonitorController::class, 'index'])
        ->middleware('permission:'.Permission::AppointmentsMonitor->value);
});
