<?php

use App\Modules\Consultations\Http\Controllers\ConsultationController;
use App\Modules\Consultations\Http\Controllers\EmergencyInfoController;
use Illuminate\Support\Facades\Route;

Route::get('emergency-info', EmergencyInfoController::class);

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::get('appointments/{appointment}/consultation', [ConsultationController::class, 'forAppointment'])->whereUuid('appointment');

    Route::prefix('consultations/{consultation}')->whereUuid('consultation')->group(function () {
        Route::get('/', [ConsultationController::class, 'show']);
        Route::post('start', [ConsultationController::class, 'start']);
        Route::post('complete', [ConsultationController::class, 'complete']);
        Route::post('interrupt', [ConsultationController::class, 'interrupt']);
        Route::put('intake', [ConsultationController::class, 'intake']);
        Route::post('escalate', [ConsultationController::class, 'escalate']);
    });
});
