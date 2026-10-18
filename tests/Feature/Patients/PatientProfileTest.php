<?php

namespace Tests\Feature\Patients;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientGuardian;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientProfileTest extends TestCase
{
    private function patientUser(array $patient = []): User
    {
        $user = User::factory()->patient()->create();
        Patient::factory()->forUser($user)->create($patient);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_registration_creates_a_patient_profile(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'account_type' => 'patient', 'name' => 'New Patient', 'phone' => '0512345678',
            'password' => 'Secret12345', 'password_confirmation' => 'Secret12345',
            'date_of_birth' => '1991-03-04', 'gender' => 'male',
        ])->assertStatus(202);

        $patient = Patient::whereHas('user', fn ($q) => $q->where('phone', '+966512345678'))->firstOrFail();
        $this->assertSame('New Patient', $patient->full_name);
        $this->assertSame('1991-03-04', $patient->date_of_birth->toDateString());
    }

    public function test_patient_views_and_updates_own_profile(): void
    {
        $this->patientUser(['date_of_birth' => '1985-01-01']);

        $this->getJson('/api/v1/patients/me')->assertOk()->assertJsonPath('data.is_minor', false)->assertJsonPath('data.can_act', true);

        $this->patchJson('/api/v1/patients/me', [
            'full_name' => 'Updated Name',
            'emergency_contact' => ['name' => 'Contact Person', 'phone' => '0555555555', 'relationship' => 'spouse'],
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'Updated Name')
            ->assertJsonPath('data.emergency_contact.phone', '+966555555555');

        // Emergency contact is encrypted at rest.
        $raw = DB::table('patients')->value('emergency_contact_phone');
        $this->assertStringNotContainsString('555555555', (string) $raw);
    }

    public function test_patient_cannot_view_another_patients_profile(): void
    {
        $other = Patient::factory()->create();
        $this->patientUser();

        $this->assertErrorCode($this->getJson("/api/v1/patients/{$other->id}"), 403, 'forbidden');
        $this->assertErrorCode($this->patchJson("/api/v1/patients/{$other->id}", ['full_name' => 'Hacked']), 403, 'forbidden');
    }

    public function test_non_patients_cannot_use_patient_endpoints(): void
    {
        $this->actingAsRole(RoleName::Doctor);

        $this->assertErrorCode($this->getJson('/api/v1/patients/me'), 403, 'forbidden');
    }

    public function test_guardian_adds_a_dependent_pending_verification(): void
    {
        $this->patientUser();

        $id = $this->postJson('/api/v1/patients/me/dependents', [
            'full_name' => 'Child Name', 'date_of_birth' => now()->subYears(6)->toDateString(), 'relationship' => 'parent',
        ])->assertCreated()
            ->assertJsonPath('data.is_minor', true)
            ->assertJsonPath('data.has_own_account', false)
            ->assertJsonPath('data.guardians.0.status', 'pending')
            ->json('data.id');

        $this->getJson('/api/v1/patients/me/dependents')->assertOk()->assertJsonCount(1, 'data');

        // Until verified, the guardian cannot act for the dependant.
        $this->assertErrorCode($this->getJson("/api/v1/patients/{$id}"), 403, 'forbidden');
    }

    public function test_verified_guardian_may_manage_and_consent_for_dependent(): void
    {
        $guardian = $this->patientUser();
        $child = Patient::factory()->dependentOf($guardian, GuardianStatus::Active)->create();
        $version = config('medconnect.consents.versions.telemedicine');

        $this->patchJson("/api/v1/patients/{$child->id}", ['full_name' => 'Renamed Child'])->assertOk();
        $this->postJson("/api/v1/patients/{$child->id}/consents", ['type' => 'telemedicine', 'version' => $version, 'granted' => true])
            ->assertCreated()
            ->assertJsonPath('data.granted', true);

        $this->assertDatabaseHas('patient_consents', ['patient_id' => $child->id, 'recorded_by_user_id' => $guardian->id]);
    }

    public function test_revoked_guardian_loses_access(): void
    {
        $guardian = $this->patientUser();
        $child = Patient::factory()->dependentOf($guardian, GuardianStatus::Revoked)->create();

        $this->assertErrorCode($this->getJson("/api/v1/patients/{$child->id}"), 403, 'forbidden');
    }

    public function test_minor_with_own_account_cannot_consent_alone(): void
    {
        $this->patientUser(['date_of_birth' => now()->subYears(15)->toDateString()]);
        $patient = Patient::firstOrFail();

        $this->getJson('/api/v1/patients/me')->assertJsonPath('data.is_minor', true)->assertJsonPath('data.can_act', false);
        $this->assertErrorCode($this->postJson("/api/v1/patients/{$patient->id}/consents", [
            'type' => 'telemedicine', 'version' => config('medconnect.consents.versions.telemedicine'), 'granted' => true,
        ]), 403, 'forbidden');
    }

    public function test_age_of_majority_is_configurable(): void
    {
        config(['medconnect.patients.age_of_majority' => 16]);
        $this->patientUser(['date_of_birth' => now()->subYears(17)->toDateString()]);

        $this->getJson('/api/v1/patients/me')->assertJsonPath('data.is_minor', false);
    }

    public function test_consent_lifecycle_and_booking_requirements(): void
    {
        $this->patientUser();
        $patient = Patient::firstOrFail();
        $url = "/api/v1/patients/{$patient->id}/consents";

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data.missing_for_booking');

        foreach (config('medconnect.consents.required_for_booking') as $type) {
            $this->postJson($url, ['type' => $type, 'version' => config("medconnect.consents.versions.{$type}"), 'granted' => true])->assertCreated();
        }
        $this->getJson($url)->assertJsonCount(0, 'data.missing_for_booking');

        // Withdrawal is a new immutable record.
        $this->postJson($url, ['type' => 'telemedicine', 'version' => config('medconnect.consents.versions.telemedicine'), 'granted' => false])->assertCreated();
        $this->getJson($url)->assertJsonPath('data.missing_for_booking', ['telemedicine']);
        $this->assertSame(4, DB::table('patient_consents')->count());

        // A new document version invalidates earlier consent.
        config(['medconnect.consents.versions.terms_of_service' => '2027-01']);
        $this->getJson($url)->assertJsonFragment(['missing_for_booking' => ['terms_of_service', 'telemedicine']]);
    }

    public function test_consent_to_an_outdated_version_is_rejected(): void
    {
        $this->patientUser();
        $patient = Patient::firstOrFail();

        $this->assertErrorCode($this->postJson("/api/v1/patients/{$patient->id}/consents", [
            'type' => 'telemedicine', 'version' => '2000-01', 'granted' => true,
        ]), 409, 'consent_version_outdated');
    }

    public function test_patient_submits_a_data_request(): void
    {
        $this->patientUser();
        $patient = Patient::firstOrFail();

        $this->postJson("/api/v1/patients/{$patient->id}/data-requests", ['type' => 'access', 'details' => 'Please send me a copy'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');

        $this->getJson("/api/v1/patients/{$patient->id}/data-requests")->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'patients.data_request_submitted', 'patient_id' => $patient->id]);
    }

    public function test_admin_verifies_guardian_links(): void
    {
        $guardian = User::factory()->patient()->create();
        Patient::factory()->dependentOf($guardian, GuardianStatus::Pending)->create();
        $link = PatientGuardian::firstOrFail();

        $this->actingAsRole(RoleName::Patient);
        $this->assertErrorCode($this->getJson('/api/v1/admin/guardian-links'), 403, 'forbidden');

        $this->actingAsRole(RoleName::Admin);
        $this->getJson('/api/v1/admin/guardian-links')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/admin/guardian-links/{$link->id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertErrorCode($this->postJson("/api/v1/admin/guardian-links/{$link->id}/decision", ['decision' => 'approve']), 409, 'invalid_state_transition');
        $this->postJson("/api/v1/admin/guardian-links/{$link->id}/decision", ['decision' => 'revoke'])->assertStatus(422);
        $this->postJson("/api/v1/admin/guardian-links/{$link->id}/decision", ['decision' => 'revoke', 'reason' => 'Court order'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');
    }
}
