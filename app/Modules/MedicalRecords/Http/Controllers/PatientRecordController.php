<?php

namespace App\Modules\MedicalRecords\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Http\Resources\ClinicalNoteResource;
use App\Modules\MedicalRecords\Services\PatientRecordService;
use App\Modules\MedicalRecords\Support\RecordKind;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's own medical record (patient or verified guardian only).
 *
 * @tags Medical records - Patient
 */
class PatientRecordController extends Controller
{
    public function __construct(
        private readonly PatientRecordService $records,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * My medical record.
     *
     * Allergies, current medications, medical history and finalised
     * consultation notes.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->summary($request, Patient::where('user_id', $request->user()->id)->firstOrFail());
    }

    /**
     * A patient's medical record (self or verified dependant).
     */
    public function summary(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('viewClinical', $patient);

        $summary = $this->records->summary($patient);
        $this->audit->record('medical_records.viewed', $patient, ['patient_id' => $patient->id, 'purpose' => 'patient_self_service']);

        return response()->json(['data' => [
            ...$summary,
            'clinical_notes' => ClinicalNoteResource::collection($summary['clinical_notes']),
        ]]);
    }

    /**
     * Add a patient-reported allergy, medication or history entry.
     *
     * `kind` is `allergies`, `medications` or `history`.
     */
    public function store(Request $request, Patient $patient, RecordKind $kind): JsonResponse
    {
        $this->authorize('viewClinical', $patient);
        $data = $request->validate($kind->rules(creating: true));

        /** @var User $user */
        $user = $request->user();
        $entry = $this->records->create($kind, $patient, $data, $user, 'patient_reported');

        return response()->json(['data' => RecordKind::present($entry)], 201);
    }

    /**
     * Correct a patient-reported entry.
     *
     * Use `status: entered_in_error` to retract an entry.
     */
    public function update(Request $request, Patient $patient, RecordKind $kind, string $entry): JsonResponse
    {
        $this->authorize('viewClinical', $patient);
        $data = $request->validate($kind->rules(creating: false));

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => RecordKind::present($this->records->update($kind, $patient, $entry, $data, $user, 'patient_reported'))]);
    }
}
