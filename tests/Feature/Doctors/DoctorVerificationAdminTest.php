<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Modules\Specialties\Models\Specialty;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorVerificationAdminTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private User $admin;

    private Doctor $doctor;

    private DoctorCredential $license;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->withMfa(self::SECRET)->create();
        $this->doctor = Doctor::factory()->status(VerificationStatus::UnderReview)
            ->withSpecialty(Specialty::factory()->create())
            ->create();
        $this->license = $this->doctor->credentials()->create([
            'type' => 'professional_license', 'title' => 'SCFHS registration', 'file_path' => 'doctors/x/license.pdf',
            'file_name' => 'license.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'sha256' => str_repeat('b', 64),
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    /** @return array<string, string> */
    private function stepUp(): array
    {
        $this->admin->forceFill(['mfa_last_timestep' => null])->save();

        return ['X-MFA-Code' => app(MfaService::class)->currentCode(self::SECRET)];
    }

    private function approve(array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/verify", $extra + [
            'decision' => 'approve',
            'note' => 'Checked against the official register.',
            'credentials' => [['id' => $this->license->id, 'status' => 'accepted']],
        ], $this->stepUp());
    }

    public function test_pending_queue_requires_the_verification_permission(): void
    {
        $this->getJson('/api/v1/admin/doctors/pending')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact.phone', $this->doctor->user->phone);

        $this->actingAsRole(RoleName::Doctor);
        $this->assertErrorCode($this->getJson('/api/v1/admin/doctors/pending'), 403, 'forbidden');
    }

    public function test_decisions_require_step_up_mfa(): void
    {
        $this->assertErrorCode(
            $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/verify", ['decision' => 'approve']),
            403, 'step_up_required',
        );

        $this->assertErrorCode(
            $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/verify", ['decision' => 'approve'], ['X-MFA-Code' => '000000']),
            403, 'step_up_required',
        );

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.step_up', 'outcome' => 'failure']);
    }

    public function test_admin_approves_with_evidence_and_reviewer_is_recorded(): void
    {
        $this->approve()->assertOk()
            ->assertJsonPath('data.verification.status', 'approved')
            ->assertJsonPath('data.credentials.0.review_status', 'accepted');

        $this->assertDatabaseHas('doctor_verifications', [
            'doctor_id' => $this->doctor->id, 'action' => 'approved', 'actor_id' => $this->admin->id,
            'from_status' => 'under_review', 'to_status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'doctors.verification.approved', 'actor_id' => $this->admin->id]);
        $this->assertTrue($this->doctor->fresh()->isPubliclyListed());
    }

    public function test_approval_requires_an_accepted_licence_document(): void
    {
        $this->assertErrorCode($this->approve(['credentials' => []]), 422, 'license_document_not_accepted');
        $this->assertSame(VerificationStatus::UnderReview, $this->doctor->fresh()->verification_status);
    }

    public function test_approval_requires_a_valid_licence(): void
    {
        $this->doctor->forceFill(['license_expires_on' => now()->subDay()])->save();

        $this->assertErrorCode($this->approve(), 422, 'license_expired');
    }

    public function test_rejection_requires_a_reason_visible_to_the_doctor(): void
    {
        $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/verify", ['decision' => 'reject'], $this->stepUp())->assertStatus(422);

        $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/verify", [
            'decision' => 'reject', 'reason' => 'Licence document is illegible.',
        ], $this->stepUp())->assertOk()->assertJsonPath('data.verification.status', 'rejected');

        Sanctum::actingAs($this->doctor->user, ['*']);
        $this->getJson('/api/v1/doctors/me/verification')
            ->assertJsonPath('data.0.reason', 'Licence document is illegible.')
            ->assertJsonMissingPath('data.0.actor_id');
    }

    public function test_suspension_removes_doctor_from_directory_and_reinstatement_restores(): void
    {
        $this->approve()->assertOk();
        $this->getJson("/api/v1/doctors/{$this->doctor->id}")->assertOk();

        $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/suspend", ['reason' => 'Complaint investigation'], $this->stepUp())
            ->assertOk()->assertJsonPath('data.verification.status', 'suspended');
        $this->getJson("/api/v1/doctors/{$this->doctor->id}")->assertNotFound();

        $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/reinstate", ['reason' => 'Cleared'], $this->stepUp())
            ->assertOk()->assertJsonPath('data.verification.status', 'approved');
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $this->assertErrorCode(
            $this->postJson("/api/v1/admin/doctors/{$this->doctor->id}/reinstate", ['reason' => 'x'], $this->stepUp()),
            409, 'invalid_state_transition',
        );
    }

    public function test_admin_cannot_verify_their_own_doctor_profile(): void
    {
        $this->doctor->forceFill(['user_id' => $this->admin->id])->save();

        $this->assertErrorCode($this->approve(), 403, 'forbidden');
    }

    public function test_admin_downloads_credentials_via_short_lived_link(): void
    {
        $this->getJson("/api/v1/admin/doctors/{$this->doctor->id}/credentials/{$this->license->id}/download")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'doctors.credential_downloaded', 'subject_id' => $this->license->id, 'actor_id' => $this->admin->id]);
    }

    public function test_lapsed_licences_expire_automatically(): void
    {
        $this->approve()->assertOk();
        $this->doctor->forceFill(['license_expires_on' => now()->subDay()])->save();

        $this->artisan('doctors:expire-licenses')->assertSuccessful();

        $this->assertSame(VerificationStatus::Expired, $this->doctor->fresh()->verification_status);
        $this->assertDatabaseHas('doctor_verifications', ['doctor_id' => $this->doctor->id, 'action' => 'expired', 'actor_id' => null, 'source' => 'system']);
    }
}
