<?php

use App\Modules\Identity\Enums\RoleName;
use App\Modules\MedicalRecords\Http\Controllers\ClinicalNoteController;
use App\Modules\MedicalRecords\Http\Controllers\ConsultationRecordController;
use App\Modules\MedicalRecords\Http\Controllers\PatientRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    // Patient side.
    Route::middleware('role:'.RoleName::Patient->value)->group(function () {
        Route::get('patients/me/medical-records', [PatientRecordController::class, 'me']);

        Route::prefix('patients/{patient}/medical-records')->whereUuid(['patient', 'entry'])->group(function () {
            Route::get('/', [PatientRecordController::class, 'summary']);
            Route::post('{kind}', [PatientRecordController::class, 'store']);
            Route::patch('{kind}/{entry}', [PatientRecordController::class, 'update']);
        });
    });

    // Doctor side - always through a consultation.
    Route::prefix('consultations/{consultation}')->whereUuid(['consultation', 'entry'])->group(function () {
        Route::middleware('role:'.RoleName::Doctor->value)->group(function () {
            Route::get('patient-record', [ConsultationRecordController::class, 'show']);
            Route::post('patient-record/{kind}', [ConsultationRecordController::class, 'store']);
            Route::patch('patient-record/{kind}/{entry}', [ConsultationRecordController::class, 'update']);
            Route::put('clinical-note', [ClinicalNoteController::class, 'saveDraft']);
            Route::post('clinical-note/finalize', [ClinicalNoteController::class, 'finalize']);
            Route::post('clinical-note/amend', [ClinicalNoteController::class, 'amend']);
        });

        Route::get('clinical-note', [ClinicalNoteController::class, 'show']);
        Route::get('clinical-note/versions', [ClinicalNoteController::class, 'versions']);
    });
});
