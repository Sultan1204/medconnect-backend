<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Specialties\Models\Specialty;
use App\Support\Files\Contracts\FileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorOnboardingTest extends TestCase
{
    private Doctor $doctor;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->specialty = Specialty::factory()->create(['slug' => 'dermatology']);
        $user = User::factory()->doctor()->withMfa()->create();
        $this->doctor = Doctor::create(['user_id' => $user->id, 'display_name_en' => $user->name]);
        Sanctum::actingAs($user, ['*']);
    }

    private function completeProfile(): void
    {
        $this->patchJson('/api/v1/doctors/me', [
            'license_number' => 'SCF-12345', 'licensing_authority' => 'SCFHS',
            'license_expires_on' => now()->addYear()->toDateString(),
            'video_fee_amount' => 20000,
            'specialties' => [['id' => $this->specialty->id, 'is_primary' => true]],
        ])->assertOk();
    }

    private function uploadLicense(): string
    {
        return $this->post('/api/v1/doctors/me/credentials', [
            'type' => 'professional_license', 'title' => 'SCFHS registration',
            'file' => UploadedFile::fake()->create('license.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_doctor_registration_creates_a_pending_profile(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'account_type' => 'doctor', 'name' => 'Dr. New', 'phone' => '0512345678',
            'password' => 'Secret12345', 'password_confirmation' => 'Secret12345',
        ])->assertStatus(202);

        $doctor = Doctor::whereHas('user', fn ($q) => $q->where('phone', '+966512345678'))->firstOrFail();
        $this->assertSame(VerificationStatus::Pending, $doctor->verification_status);
        $this->assertFalse($doctor->isPubliclyListed());
    }

    public function test_profile_reports_missing_requirements(): void
    {
        $this->getJson('/api/v1/doctors/me')
            ->assertOk()
            ->assertJsonPath('data.verification.status', 'pending')
            ->assertJsonPath('data.verification.missing_requirements', [
                'license_number', 'licensing_authority', 'license_expires_on', 'specialties', 'consultation_fees', 'professional_license_document',
            ]);

        $this->assertErrorCode($this->postJson('/api/v1/doctors/me/verification'), 422, 'verification_requirements_missing');
    }

    public function test_exactly_one_primary_specialty_is_required(): void
    {
        $other = Specialty::factory()->create();

        $this->patchJson('/api/v1/doctors/me', ['specialties' => [
            ['id' => $this->specialty->id, 'is_primary' => true],
            ['id' => $other->id, 'is_primary' => true],
        ]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['specialties']]]]);
    }

    public function test_inactive_specialties_cannot_be_selected(): void
    {
        $inactive = Specialty::factory()->create(['is_active' => false]);

        $this->patchJson('/api/v1/doctors/me', ['specialties' => [['id' => $inactive->id, 'is_primary' => true]]])->assertStatus(422);
    }

    public function test_credential_upload_is_private_and_downloadable_via_short_lived_link(): void
    {
        $id = $this->uploadLicense();

        $path = $this->doctor->credentials()->firstOrFail()->file_path;
        Storage::disk('private')->assertExists($path);
        $this->assertStringStartsWith("doctors/{$this->doctor->id}/credentials/", $path);
        $this->assertStringNotContainsString('license', $path); // random name, not the client filename

        $url = $this->getJson("/api/v1/doctors/me/credentials/{$id}/download")->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Disposition');

        // Tampered and expired links are refused.
        $this->get($url.'x')->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $this->post('/api/v1/doctors/me/credentials', [
            'type' => 'qualification', 'title' => 'Script',
            'file' => UploadedFile::fake()->create('evil.php', 1, 'application/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_files_failing_the_malware_scan_are_rejected(): void
    {
        $this->app->bind(FileScanner::class, fn () => new class implements FileScanner
        {
            public function isClean(string $absolutePath): bool
            {
                return false;
            }
        });

        $response = $this->post('/api/v1/doctors/me/credentials', [
            'type' => 'qualification', 'title' => 'Board certificate',
            'file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $this->assertErrorCode($response, 422, 'file_rejected');
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_complete_profile_can_be_submitted_for_review(): void
    {
        $this->completeProfile();
        $this->uploadLicense();

        $this->postJson('/api/v1/doctors/me/verification')
            ->assertOk()
            ->assertJsonPath('data.verification.status', 'under_review');

        $this->getJson('/api/v1/doctors/me/verification')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'submitted')
            ->assertJsonPath('data.0.source', 'manual')
            ->assertJsonMissingPath('data.0.actor_id');

        // Cannot submit twice.
        $this->assertErrorCode($this->postJson('/api/v1/doctors/me/verification'), 409, 'invalid_state_transition');
    }

    public function test_changing_licence_after_approval_requires_re_verification(): void
    {
        $this->completeProfile();
        $this->doctor->forceFill(['verification_status' => VerificationStatus::Approved])->save();

        $this->patchJson('/api/v1/doctors/me', ['bio_en' => 'Updated bio'])->assertOk()->assertJsonPath('data.verification.status', 'approved');
        $this->patchJson('/api/v1/doctors/me', ['license_number' => 'SCF-99999'])->assertOk()->assertJsonPath('data.verification.status', 'under_review');

        $this->assertDatabaseHas('doctor_verifications', ['doctor_id' => $this->doctor->id, 'action' => 'license_changed']);
    }

    public function test_licence_numbers_are_unique_per_authority(): void
    {
        Doctor::factory()->create(['license_number' => 'SCF-12345', 'licensing_authority' => 'SCFHS']);

        $this->assertErrorCode($this->patchJson('/api/v1/doctors/me', ['license_number' => 'SCF-12345', 'licensing_authority' => 'SCFHS']), 422, 'license_already_registered');
    }

    public function test_accepted_credentials_cannot_be_deleted(): void
    {
        $id = $this->uploadLicense();
        $this->doctor->credentials()->whereKey($id)->update(['review_status' => 'accepted']);

        $this->assertErrorCode($this->deleteJson("/api/v1/doctors/me/credentials/{$id}"), 409, 'credential_locked');
    }

    public function test_doctor_cannot_access_another_doctors_credential(): void
    {
        $other = Doctor::factory()->create();
        $credential = $other->credentials()->create([
            'type' => 'professional_license', 'title' => 'x', 'file_path' => 'doctors/x/y.pdf', 'file_name' => 'y.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        ]);

        $this->assertErrorCode($this->getJson("/api/v1/doctors/me/credentials/{$credential->id}/download"), 404, 'not_found');
    }
}
