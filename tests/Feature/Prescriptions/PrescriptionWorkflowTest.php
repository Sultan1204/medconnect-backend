<?php

namespace Tests\Feature\Prescriptions;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Models\Prescription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PrescriptionFixtures;
use Tests\TestCase;

class PrescriptionWorkflowTest extends TestCase
{
    use PrescriptionFixtures;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consultation = $this->activeConsultation();
        Sanctum::actingAs($this->consultation->doctor->user, ['*']);
    }

    private function draft(array $items = [], ?Consultation $consultation = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/prescriptions/drafts', [
            'consultation_id' => ($consultation ?? $this->consultation)->id,
            'clinical_indication' => 'Acute bacterial sinusitis',
            'items' => $items ?: [$this->item()],
        ]);
    }

    public function test_draft_is_explicitly_not_a_valid_prescription(): void
    {
        $this->draft()->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.legal_validity', 'not_valid')
            ->assertJsonPath('data.items.0.catalog_code', 'AMOX-500')
            ->assertJsonPath('data.items.0.instructions', 'Take with water after meals');

        $this->assertStringNotContainsString('sinusitis', (string) DB::table('prescriptions')->value('clinical_indication'));
    }

    public function test_only_the_treating_doctor_during_a_started_consultation_can_prescribe(): void
    {
        Sanctum::actingAs(Doctor::factory()->approved()->create()->user, ['*']);
        $this->assertErrorCode($this->draft(), 403, 'no_care_relationship');

        $scheduled = $this->scheduledConsultation();
        Sanctum::actingAs($scheduled->doctor->user, ['*']);
        $this->assertErrorCode($this->draft(consultation: $scheduled), 409, 'consultation_not_started');

        Sanctum::actingAs($this->consultation->patient->user, ['*']);
        $this->assertErrorCode($this->draft(), 403, 'forbidden');
    }

    public function test_items_are_validated(): void
    {
        $this->draft([$this->item(['catalog_system' => 'FREE_TEXT'])])->assertStatus(422);
        $this->draft([$this->item(['route' => 'teleport'])])->assertStatus(422);
        $this->draft([$this->item(['quantity' => 0])])->assertStatus(422);
    }

    public function test_clean_prescription_goes_to_the_e_prescribing_service_and_is_issued_only_by_it(): void
    {
        $id = $this->draft()->json('data.id');

        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_external_approval')
            ->assertJsonPath('data.safety_check.status', 'passed')
            ->assertJsonPath('data.legal_validity', 'not_valid');
        $this->assertStringStartsWith('MOCK-RX-', Prescription::findOrFail($id)->external_reference);

        $this->postJson("/api/v1/dev/mock-prescriptions/{$id}/decision", ['decision' => 'issue'])->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.legal_validity', 'simulated_not_valid');

        $this->assertNotNull(Prescription::findOrFail($id)->expires_at);
        $this->assertTrue(Prescription::findOrFail($id)->is_simulated);
    }

    public function test_allergy_warnings_require_a_justified_acknowledgement(): void
    {
        PatientAllergy::create(['patient_id' => $this->consultation->patient_id, 'allergen' => 'Amoxicillin', 'severity' => 'severe',
            'source' => 'patient_reported', 'recorded_by_user_id' => $this->consultation->patient->user_id]);
        $id = $this->draft()->json('data.id');

        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_validation')
            ->assertJsonPath('data.safety_check.status', 'warnings')
            ->assertJsonPath('data.safety_check.findings.0.type', 'allergy');

        $this->postJson("/api/v1/prescriptions/{$id}/acknowledge-warnings", ['justification' => 'short'])->assertStatus(422);
        $this->postJson("/api/v1/prescriptions/{$id}/acknowledge-warnings", ['justification' => 'Previous reaction was mild rash; benefits outweigh risk, patient counselled.'])
            ->assertOk()->assertJsonPath('data.status', 'pending_external_approval');
    }

    public function test_blocking_findings_require_editing(): void
    {
        $id = $this->draft([$this->item(), $this->item(['dose' => '2 capsules'])])->json('data.id'); // duplicate code

        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk()->assertJsonPath('data.safety_check.status', 'blocked');
        $this->assertErrorCode($this->postJson("/api/v1/prescriptions/{$id}/acknowledge-warnings", ['justification' => 'Trying to override a block']), 409, 'no_warnings_to_acknowledge');

        $this->putJson("/api/v1/prescriptions/{$id}", ['items' => [$this->item()]])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_external_approval');
    }

    public function test_controlled_medicines_follow_configured_rules(): void
    {
        config(['medconnect.prescriptions.controlled_codes' => ['CTRL-1']]);
        $id = $this->draft([$this->item(['catalog_code' => 'CTRL-1', 'medication_name' => 'Restricted medicine'])])->json('data.id');

        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk()
            ->assertJsonPath('data.safety_check.status', 'blocked')
            ->assertJsonPath('data.safety_check.findings.0.type', 'controlled_medication')
            ->assertJsonPath('data.items.0.is_controlled', true);
    }

    public function test_submitted_prescriptions_cannot_be_edited(): void
    {
        $id = $this->draft()->json('data.id');
        $this->postJson("/api/v1/prescriptions/{$id}/submit")->assertOk();

        $this->assertErrorCode($this->putJson("/api/v1/prescriptions/{$id}", ['items' => [$this->item(['quantity' => 99])]]), 409, 'prescription_locked');
    }

    public function test_patient_sees_submitted_prescriptions_but_never_drafts(): void
    {
        $draftId = $this->draft()->json('data.id');
        $issued = $this->issuedPrescription($this->consultation);

        Sanctum::actingAs($this->consultation->patient->user, ['*']);
        $this->assertErrorCode($this->getJson("/api/v1/prescriptions/{$draftId}"), 403, 'forbidden');
        $this->getJson("/api/v1/prescriptions/{$issued->id}")->assertOk()
            ->assertJsonMissingPath('data.safety_check')
            ->assertJsonPath('data.legal_validity', 'simulated_not_valid');

        $this->getJson("/api/v1/patients/{$this->consultation->patient_id}/prescriptions")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cancel_and_expiry(): void
    {
        $issued = $this->issuedPrescription($this->consultation);
        $this->postJson("/api/v1/prescriptions/{$issued->id}/cancel", ['reason' => 'Wrong patient weight'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertErrorCode($this->postJson("/api/v1/prescriptions/{$issued->id}/cancel", ['reason' => 'again']), 409, 'prescription_not_cancellable');

        $other = $this->issuedPrescription($this->consultation);
        $this->travel(31)->days();
        $this->artisan('prescriptions:expire')->assertSuccessful();
        $this->assertSame(PrescriptionStatus::Expired, $other->fresh()->status);
    }

    public function test_history_is_recorded_and_append_only(): void
    {
        $issued = $this->issuedPrescription($this->consultation);

        $this->assertSame(['created', 'safety_check_passed', 'submitted_to_gateway', 'issued'], $issued->events()->pluck('type')->all());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::transaction(fn () => DB::table('prescription_events')->delete());
    }
}
