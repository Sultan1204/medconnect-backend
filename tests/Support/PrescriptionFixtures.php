<?php

namespace Tests\Support;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Services\PrescriptionWorkflow;

trait PrescriptionFixtures
{
    use ConsultationFixtures;

    /** @return array<string, mixed> */
    protected function item(array $overrides = []): array
    {
        return array_merge([
            'catalog_system' => 'MOCK', 'catalog_code' => 'AMOX-500', 'medication_name' => 'Amoxicillin 500 mg capsules',
            'strength' => '500 mg', 'dosage_form' => 'capsule', 'dose' => '1 capsule', 'route' => 'oral',
            'frequency' => 'every 8 hours', 'duration_days' => 7, 'quantity' => 21, 'quantity_unit' => 'capsule',
            'instructions' => 'Take with water after meals',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     */
    protected function issuedPrescription(?Consultation $consultation = null, ?array $items = null): Prescription
    {
        $consultation ??= $this->activeConsultation();
        $workflow = app(PrescriptionWorkflow::class);

        $prescription = $workflow->createDraft($consultation, $consultation->doctor->user, $items ?? [$this->item()], 'Acute sinusitis');
        $prescription = $workflow->submit($prescription, $consultation->doctor->user);

        return $workflow->applyGatewayDecision($prescription, new GatewayDecision(GatewayDecision::ISSUED, (string) $prescription->external_reference))
            ->load(['items', 'patient.user', 'consultation']);
    }

    protected function grantPharmacyConsent(Prescription $prescription): void
    {
        PatientConsent::create([
            'patient_id' => $prescription->patient_id, 'consent_type' => 'pharmacy_data_sharing', 'granted' => true,
            'version' => config('medconnect.consents.versions.pharmacy_data_sharing'), 'recorded_by_user_id' => $prescription->patient->user_id,
        ]);
    }

    /**
     * @return array{Pharmacy, PharmacyBranch}
     */
    protected function approvedPharmacy(string $status = 'approved', array $branch = []): array
    {
        $pharmacy = Pharmacy::create([
            'name_en' => 'Synthetic Pharmacy '.uniqid(), 'name_ar' => 'صيدلية تجريبية', 'license_number' => 'PH-'.uniqid(),
            'licensing_authority' => 'MOH', 'license_expires_on' => now()->addYear()->toDateString(),
        ]);
        $pharmacy->forceFill(['verification_status' => $status])->save();

        $branch = $pharmacy->branches()->create($branch + [
            'name_en' => 'Main branch', 'name_ar' => 'الفرع الرئيسي', 'city' => 'Riyadh',
            'address_line' => 'Synthetic street 1', 'latitude' => 24.7136, 'longitude' => 46.6753,
        ]);

        return [$pharmacy, $branch];
    }

    protected function pharmacyUser(Pharmacy $pharmacy, string $role = 'pharmacist', ?PharmacyBranch $branch = null): User
    {
        $user = User::factory()->pharmacyStaff()->create();
        PharmacyStaff::create(['pharmacy_id' => $pharmacy->id, 'branch_id' => $branch?->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }
}
