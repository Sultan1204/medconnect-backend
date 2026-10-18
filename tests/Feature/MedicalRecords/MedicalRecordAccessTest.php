<?php

namespace Tests\Feature\MedicalRecords;

use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\MedicalRecords\Models\ClinicalNote;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\MedicalRecords\Services\ClinicalNoteService;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class MedicalRecordAccessTest extends TestCase
{
    use ConsultationFixtures;

    public function test_patient_maintains_their_own_record_which_is_encrypted_at_rest(): void
    {
        $patient = Patient::factory()->create();
        Sanctum::actingAs($patient->user, ['*']);
        $base = "/api/v1/patients/{$patient->id}/medical-records";

        $this->postJson("{$base}/allergies", ['allergen' => 'Penicillin', 'reaction' => 'Hives', 'severity' => 'moderate'])->assertCreated()
            ->assertJsonPath('data.source', 'patient_reported');
        $this->postJson("{$base}/medications", ['name' => 'Metformin', 'dosage' => '500 mg', 'frequency' => 'twice daily'])->assertCreated();
        $this->postJson("{$base}/history", ['type' => 'chronic_condition', 'title' => 'Type 2 diabetes', 'onset_date' => '2019-03-01'])->assertCreated();

        $this->getJson('/api/v1/patients/me/medical-records')->assertOk()
            ->assertJsonPath('data.allergies.0.allergen', 'Penicillin')
            ->assertJsonPath('data.medications.0.name', 'Metformin')
            ->assertJsonPath('data.history.0.title', 'Type 2 diabetes');

        $this->assertStringNotContainsString('Penicillin', (string) DB::table('patient_allergies')->value('allergen'));
        $this->assertStringNotContainsString('Metformin', (string) DB::table('patient_medications')->value('name'));
        $this->assertStringNotContainsString('diabetes', (string) DB::table('medical_records')->value('title'));
    }

    public function test_entries_are_retracted_not_deleted(): void
    {
        $patient = Patient::factory()->create();
        Sanctum::actingAs($patient->user, ['*']);
        $id = $this->postJson("/api/v1/patients/{$patient->id}/medical-records/allergies", ['allergen' => 'Latex'])->json('data.id');

        $this->patchJson("/api/v1/patients/{$patient->id}/medical-records/allergies/{$id}", ['status' => 'entered_in_error'])->assertOk();

        $this->getJson('/api/v1/patients/me/medical-records')->assertJsonCount(0, 'data.allergies');
        $this->assertDatabaseHas('patient_allergies', ['id' => $id, 'status' => 'entered_in_error']);
    }

    public function test_patients_cannot_alter_clinician_entries(): void
    {
        $consultation = $this->activeConsultation();
        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $id = $this->postJson("/api/v1/consultations/{$consultation->id}/patient-record/allergies", ['allergen' => 'Sulfa', 'severity' => 'severe'])
            ->assertCreated()->assertJsonPath('data.source', 'clinician')->json('data.id');

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $this->assertErrorCode(
            $this->patchJson("/api/v1/patients/{$consultation->patient_id}/medical-records/allergies/{$id}", ['status' => 'entered_in_error']),
            403, 'clinician_entry',
        );
    }

    public function test_verified_guardian_sees_dependants_record_but_others_do_not(): void
    {
        $guardianPatient = Patient::factory()->create();
        $child = Patient::factory()->dependentOf($guardianPatient->user, GuardianStatus::Active)->create();
        PatientAllergy::create(['patient_id' => $child->id, 'allergen' => 'Peanuts', 'source' => 'patient_reported', 'recorded_by_user_id' => $guardianPatient->user_id]);

        Sanctum::actingAs($guardianPatient->user, ['*']);
        $this->getJson("/api/v1/patients/{$child->id}/medical-records")->assertOk()->assertJsonPath('data.allergies.0.allergen', 'Peanuts');

        $this->actingAsRole(RoleName::Patient);
        $this->assertErrorCode($this->getJson("/api/v1/patients/{$child->id}/medical-records"), 403, 'forbidden');
    }

    public function test_administrators_have_no_clinical_access_by_role(): void
    {
        $consultation = $this->activeConsultation();

        foreach ([RoleName::Admin, RoleName::SuperAdmin] as $role) {
            $this->actingAsRole($role);
            $this->assertErrorCode($this->getJson("/api/v1/patients/{$consultation->patient_id}/medical-records"), 403, 'forbidden');
            $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'forbidden');
            $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/clinical-note"), 404, 'not_found');
        }
    }

    public function test_treating_doctor_accesses_record_within_the_care_window_with_purpose_audited(): void
    {
        $consultation = $this->activeConsultation();
        Sanctum::actingAs($consultation->doctor->user, ['*']);

        $this->getJson("/api/v1/consultations/{$consultation->id}/patient-record")->assertOk()
            ->assertJsonPath('data.patient.full_name', $consultation->patient->full_name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medical_records.viewed', 'actor_id' => $consultation->doctor->user_id,
            'patient_id' => $consultation->patient_id, 'purpose' => 'consultation:'.$consultation->id,
        ]);

        app(ConsultationLifecycle::class)->complete($consultation, $consultation->doctor->user);
        $this->travel(config('medconnect.consultations.care_access_days') + 1)->days();

        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'no_care_relationship');
    }

    public function test_doctor_access_opens_shortly_before_the_appointment_only(): void
    {
        $consultation = $this->scheduledConsultation(startsInMinutes: 60 * 48);
        Sanctum::actingAs($consultation->doctor->user, ['*']);

        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'no_care_relationship');

        $this->travel(25)->hours();
        $this->getJson("/api/v1/consultations/{$consultation->id}/patient-record")->assertOk();
    }

    public function test_other_doctors_and_ineligible_doctors_are_refused(): void
    {
        $consultation = $this->activeConsultation();

        Sanctum::actingAs(Doctor::factory()->approved()->create()->user, ['*']);
        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'no_care_relationship');

        $consultation->doctor->forceFill(['verification_status' => 'suspended'])->save();
        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'no_care_relationship');
    }

    public function test_doctor_sees_only_their_own_previous_notes(): void
    {
        $patient = Patient::factory()->create();
        $other = $this->activeConsultation(patient: $patient);
        app(ClinicalNoteService::class)->saveDraft($other, $other->doctor->user, ['assessment' => 'A', 'plan' => 'P']);
        $note = ClinicalNote::firstOrFail();
        app(ClinicalNoteService::class)->finalize($note, $other->doctor->user);

        $mine = $this->scheduledConsultation(startsInMinutes: 60, patient: $patient);
        Sanctum::actingAs($mine->doctor->user, ['*']);

        $this->getJson("/api/v1/consultations/{$mine->id}/patient-record")->assertOk()->assertJsonCount(0, 'data.clinical_notes');
    }
}
