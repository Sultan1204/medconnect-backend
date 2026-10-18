<?php

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Prescriptions\Http\Controllers\MockPrescriptionController;
use App\Modules\Prescriptions\Http\Controllers\PrescriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::middleware('role:'.RoleName::Doctor->value)->group(function () {
        Route::post('prescriptions/drafts', [PrescriptionController::class, 'store']);

        Route::prefix('prescriptions/{prescription}')->whereUuid('prescription')->group(function () {
            Route::put('/', [PrescriptionController::class, 'update']);
            Route::post('submit', [PrescriptionController::class, 'submit']);
            Route::post('acknowledge-warnings', [PrescriptionController::class, 'acknowledgeWarnings']);
            Route::post('cancel', [PrescriptionController::class, 'cancel']);
        });
    });

    Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show'])->whereUuid('prescription');
    Route::get('consultations/{consultation}/prescriptions', [PrescriptionController::class, 'forConsultation'])->whereUuid('consultation');
    Route::get('patients/{patient}/prescriptions', [PrescriptionController::class, 'forPatient'])
        ->whereUuid('patient')->middleware('role:'.RoleName::Patient->value);
});

if (! app()->isProduction() && config('medconnect.drivers.prescription') === 'mock') {
    Route::post('dev/mock-prescriptions/{prescription}/decision', MockPrescriptionController::class)->whereUuid('prescription');
}
