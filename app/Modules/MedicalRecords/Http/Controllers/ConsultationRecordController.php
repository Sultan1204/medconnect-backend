<?php

namespace App\Modules\MedicalRecords\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Http\Resources\ClinicalNoteResource;
use App\Modules\MedicalRecords\Services\CareAccess;
use App\Modules\MedicalRecords\Services\PatientRecordService;
use App\Modules\MedicalRecords\Support\RecordKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The treating doctor's view of the patient's record, always scoped to a
 * consultation (the purpose of access) and its care window.
 *
 * @tags Medical records - Doctor
 */
class ConsultationRecordController extends Controller
{
    public function __construct(
        private readonly PatientRecordService $records,
        private readonly CareAccess $care,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Patient record for this consultation.
     *
     * Includes allergies, medications and history, plus this doctor's own
     * previous notes for the patient. Every access is audited.
     */
    public function show(Request $request, Consultation $consultation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->care->authorize($user, $consultation);

        $summary = $this->records->summary($consultation->patient, $consultation->doctor_id);
        $this->audit->record('medical_records.viewed', $consultation->patient, [
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
        ]);

        return response()->json(['data' => [
            ...$summary,
            'clinical_notes' => ClinicalNoteResource::collection($summary['clinical_notes']),
            'patient' => [
                'full_name' => $consultation->patient->full_name,
                'age' => $consultation->patient->age(),
                'gender' => $consultation->patient->gender,
            ],
        ]]);
    }

    /**
     * Record an allergy, medication or history entry as clinician.
     */
    public function store(Request $request, Consultation $consultation, RecordKind $kind): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->care->authorize($user, $consultation);
        $data = $request->validate($kind->rules(creating: true));

        $entry = $this->records->create($kind, $consultation->patient, $data, $user, 'clinician', $consultation);

        return response()->json(['data' => RecordKind::present($entry)], 201);
    }

    /**
     * Correct an entry as clinician (any source).
     */
    public function update(Request $request, Consultation $consultation, RecordKind $kind, string $entry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->care->authorize($user, $consultation);
        $data = $request->validate($kind->rules(creating: false));

        return response()->json(['data' => RecordKind::present(
            $this->records->update($kind, $consultation->patient, $entry, $data, $user, 'clinician', $consultation),
        )]);
    }
}
