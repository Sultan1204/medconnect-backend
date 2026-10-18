<?php

namespace Tests\Feature\Pharmacies;

use App\Modules\Identity\Models\User;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Services\PrescriptionWorkflow;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PrescriptionFixtures;
use Tests\TestCase;

class DispensingTest extends TestCase
{
    use PrescriptionFixtures;

    private Prescription $prescription;

    private Pharmacy $pharmacy;

    private PharmacyBranch $branch;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prescription = $this->issuedPrescription();
        [$this->pharmacy, $this->branch] = $this->approvedPharmacy();
        $this->pharmacist = $this->pharmacyUser($this->pharmacy);
    }

    private function asPatient(): void
    {
        Sanctum::actingAs($this->prescription->patient->user, ['*']);
    }

    private function send(?PharmacyBranch $branch = null): TestResponse
    {
        return $this->postJson("/api/v1/prescriptions/{$this->prescription->id}/dispense-requests", ['branch_id' => ($branch ?? $this->branch)->id]);
    }

    private function sentRequestId(): string
    {
        $this->grantPharmacyConsent($this->prescription);
        $this->asPatient();

        return $this->send()->assertCreated()->json('data.id');
    }

    private function moveTo(string $id, string $status, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/pharmacy/dispense-requests/{$id}/status", ['status' => $status] + $extra);
    }

    public function test_patients_see_only_eligible_branches_sorted_by_distance(): void
    {
        [, $far] = $this->approvedPharmacy(branch: ['latitude' => 24.80, 'longitude' => 46.70]);
        [, $suspendedBranch] = $this->approvedPharmacy('suspended');
        $this->branch->pharmacy->branches()->create(['name_en' => 'Closed', 'name_ar' => 'مغلق', 'city' => 'Riyadh', 'address_line' => 'x', 'is_active' => false]);
        $this->asPatient();

        $ids = array_column($this->getJson('/api/v1/pharmacies/branches?lat=24.7136&lng=46.6753')->assertOk()->json('data'), 'id');

        $this->assertSame([$this->branch->id, $far->id], $ids);
        $this->assertNotContains($suspendedBranch->id, $ids);
    }

    public function test_sharing_with_a_pharmacy_requires_consent(): void
    {
        $this->asPatient();
        $this->assertErrorCode($this->send(), 422, 'consent_required');

        $this->grantPharmacyConsent($this->prescription);
        $this->send()->assertCreated()->assertJsonPath('data.status', 'requested');
    }

    public function test_one_pharmacy_at_a_time(): void
    {
        $id = $this->sentRequestId();
        [, $other] = $this->approvedPharmacy();

        $this->assertErrorCode($this->send($other), 409, 'dispense_request_exists');

        $this->postJson("/api/v1/dispense-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->send($other)->assertCreated();
    }

    public function test_only_issued_prescriptions_can_be_sent(): void
    {
        $draft = app(PrescriptionWorkflow::class)->createDraft($this->prescription->consultation, $this->prescription->consultation->doctor->user, [$this->item()], null);
        $this->grantPharmacyConsent($this->prescription);
        $this->asPatient();

        $this->assertErrorCode($this->postJson("/api/v1/prescriptions/{$draft->id}/dispense-requests", ['branch_id' => $this->branch->id]), 409, 'prescription_not_dispensable');
    }

    public function test_simulated_prescriptions_are_never_dispensable_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse($this->prescription->fresh()->isDispensable());

        $this->app['env'] = 'testing';
        $this->assertTrue($this->prescription->fresh()->isDispensable());
    }

    public function test_full_dispensing_workflow_updates_the_prescription(): void
    {
        $id = $this->sentRequestId();
        Sanctum::actingAs($this->pharmacist, ['*']);

        $this->getJson('/api/v1/pharmacy/dispense-requests')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.prescription.items.0.medication_name', 'Amoxicillin 500 mg capsules')
            ->assertJsonPath('data.0.patient.full_name', $this->prescription->patient->full_name);

        $this->getJson("/api/v1/pharmacy/dispense-requests/{$id}/verify")->assertOk()->assertJsonPath('data.valid_for_dispensing', true);

        $this->moveTo($id, 'received')->assertOk();
        $this->moveTo($id, 'under_review')->assertOk();
        $this->moveTo($id, 'ready', ['note' => 'Ready for pickup at counter 2'])->assertOk()->assertJsonPath('data.pharmacy_note', 'Ready for pickup at counter 2');
        $this->moveTo($id, 'dispensed')->assertOk()->assertJsonPath('data.status', 'dispensed');

        $this->assertSame(PrescriptionStatus::Dispensed, $this->prescription->fresh()->status);
        $this->assertSame(['requested', 'received', 'under_review', 'ready', 'dispensed'], DispenseRequest::findOrFail($id)->events()->pluck('to_status')->all());
    }

    public function test_pharmacy_view_excludes_clinical_context(): void
    {
        $id = $this->sentRequestId();
        Sanctum::actingAs($this->pharmacist, ['*']);

        $body = $this->getJson("/api/v1/pharmacy/dispense-requests/{$id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('sinusitis', $body);
        $this->assertStringNotContainsString('clinical_indication', $body);
        $this->assertStringNotContainsString('consultation', $body);
    }

    public function test_invalid_transitions_and_role_limits(): void
    {
        $id = $this->sentRequestId();
        $assistant = $this->pharmacyUser($this->pharmacy, 'assistant');

        Sanctum::actingAs($this->pharmacist, ['*']);
        $this->assertErrorCode($this->moveTo($id, 'dispensed'), 409, 'invalid_state_transition');
        $this->assertErrorCode($this->moveTo($id, 'rejected'), 422, 'rejection_reason_required');
        $this->moveTo($id, 'under_review')->assertOk();

        Sanctum::actingAs($assistant, ['*']);
        $this->assertErrorCode($this->moveTo($id, 'ready'), 403, 'pharmacist_required');

        Sanctum::actingAs($this->pharmacist, ['*']);
        $this->moveTo($id, 'rejected', ['reason' => 'out_of_stock'])->assertOk()->assertJsonPath('data.rejection_reason', 'out_of_stock');
    }

    public function test_staff_only_see_their_own_pharmacy_and_branch(): void
    {
        $id = $this->sentRequestId();
        [$otherPharmacy, $otherBranch] = $this->approvedPharmacy();
        $outsider = $this->pharmacyUser($otherPharmacy);
        $otherBranchOnly = $this->pharmacyUser($this->pharmacy, 'pharmacist', $this->pharmacy->branches()->create([
            'name_en' => 'Second', 'name_ar' => 'الثاني', 'city' => 'Riyadh', 'address_line' => 'y',
        ]));

        foreach ([$outsider, $otherBranchOnly] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/v1/pharmacy/dispense-requests')->assertOk()->assertJsonCount(0, 'data');
            $this->assertErrorCode($this->getJson("/api/v1/pharmacy/dispense-requests/{$id}"), 404, 'not_found');
            $this->assertErrorCode($this->moveTo($id, 'received'), 404, 'not_found');
        }
        unset($otherBranch);
    }

    public function test_suspended_pharmacy_staff_lose_access(): void
    {
        $id = $this->sentRequestId();
        $this->pharmacy->forceFill(['verification_status' => 'suspended'])->save();
        Sanctum::actingAs($this->pharmacist, ['*']);

        $this->assertErrorCode($this->getJson("/api/v1/pharmacy/dispense-requests/{$id}"), 403, 'not_pharmacy_staff');
    }

    public function test_pharmacy_staff_cannot_reach_clinical_endpoints(): void
    {
        Sanctum::actingAs($this->pharmacist, ['*']);
        $consultation = $this->prescription->consultation;

        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}"), 403, 'forbidden');
        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/messages"), 403, 'forbidden');
        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}/patient-record"), 403, 'forbidden');
        $this->assertErrorCode($this->getJson("/api/v1/patients/{$this->prescription->patient_id}/medical-records"), 403, 'forbidden');
        $this->assertErrorCode($this->getJson("/api/v1/prescriptions/{$this->prescription->id}"), 403, 'forbidden');
    }

    public function test_cancelling_the_prescription_withdraws_open_pharmacy_requests(): void
    {
        $id = $this->sentRequestId();

        app(PrescriptionWorkflow::class)->cancel($this->prescription, $this->prescription->consultation->doctor->user, 'Changed treatment');

        $this->assertSame('cancelled', DispenseRequest::findOrFail($id)->status->value);
    }

    public function test_operational_report(): void
    {
        $id = $this->sentRequestId();
        Sanctum::actingAs($this->pharmacist, ['*']);
        $this->moveTo($id, 'under_review');
        $this->moveTo($id, 'ready');

        $this->getJson('/api/v1/pharmacy/reports/summary')->assertOk()
            ->assertJsonPath('data.by_status.ready', 1)
            ->assertJsonPath('data.period_days', 30);
    }
}
