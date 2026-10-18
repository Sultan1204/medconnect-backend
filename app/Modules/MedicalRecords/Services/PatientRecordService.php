<?php

namespace App\Modules\MedicalRecords\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Models\ClinicalNote;
use App\Modules\MedicalRecords\Models\MedicalRecord;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\MedicalRecords\Models\PatientMedication;
use App\Modules\MedicalRecords\Support\RecordKind;
use App\Modules\Patients\Models\Patient;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;

class PatientRecordService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Allergies, medications, history and finalised notes.
     *
     * @param  string|null  $notesByDoctorId  limit notes to one doctor (care-relationship view)
     * @return array<string, mixed>
     */
    public function summary(Patient $patient, ?string $notesByDoctorId = null): array
    {
        $current = fn ($q) => $q->where('patient_id', $patient->id)->where('status', '!=', 'entered_in_error')->latest()->get();

        return [
            'patient_id' => $patient->id,
            'allergies' => $current(PatientAllergy::query())->map(RecordKind::present(...))->values(),
            'medications' => $current(PatientMedication::query())->map(RecordKind::present(...))->values(),
            'history' => $current(MedicalRecord::query())->map(RecordKind::present(...))->values(),
            'clinical_notes' => ClinicalNote::query()
                ->where('patient_id', $patient->id)
                ->where('status', '!=', 'draft')
                ->when($notesByDoctorId, fn ($q) => $q->where('author_doctor_id', $notesByDoctorId))
                ->with(['latestVersion', 'author'])
                ->latest('finalized_at')
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(RecordKind $kind, Patient $patient, array $data, User $actor, string $source, ?Consultation $consultation = null): Model
    {
        $class = $kind->model();

        /** @var Model $entry */
        $entry = $class::create($data + [
            'patient_id' => $patient->id,
            'source' => $source,
            'recorded_by_user_id' => $actor->id,
            'consultation_id' => $consultation?->id,
        ]);

        $this->audit->record("medical_records.{$kind->value}.created", $entry, [
            'actor' => $actor,
            'patient_id' => $patient->id,
            'purpose' => $consultation ? 'consultation:'.$consultation->id : 'patient_self_service',
            'metadata' => ['source' => $source],
        ]);

        return $entry;
    }

    /**
     * Entries are corrected in place only by the side that recorded them;
     * removal is `status: entered_in_error`, never a delete.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(RecordKind $kind, Patient $patient, string $entryId, array $data, User $actor, string $source, ?Consultation $consultation = null): Model
    {
        $class = $kind->model();
        /** @var Model $entry */
        $entry = $class::where('patient_id', $patient->id)->findOrFail($entryId);

        if ($source === 'patient_reported' && $entry->getAttribute('source') !== 'patient_reported') {
            throw ApiException::forbidden('Entries recorded by a clinician can only be changed by a clinician.', 'clinician_entry');
        }

        $entry->fill($data)->save();

        $this->audit->record("medical_records.{$kind->value}.updated", $entry, [
            'actor' => $actor,
            'patient_id' => $patient->id,
            'purpose' => $consultation ? 'consultation:'.$consultation->id : 'patient_self_service',
            'changes' => AuditLogger::changedFields($entry),
        ]);

        return $entry;
    }
}
