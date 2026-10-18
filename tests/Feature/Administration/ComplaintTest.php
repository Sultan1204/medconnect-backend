<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Models\Complaint;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use ConsultationFixtures;

    private function body(array $over = []): array
    {
        return $over + ['subject_type' => 'platform', 'category' => 'technical', 'description' => 'The app logged me out repeatedly.'];
    }

    private function handler(): User
    {
        $admin = User::factory()->admin()->withMfa()->create();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_a_patient_files_a_platform_complaint(): void
    {
        $this->actingAsRole(RoleName::Patient);

        $this->postJson('/api/v1/complaints', $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonMissingPath('data.priority');

        $this->assertDatabaseCount('complaints', 1);
        $this->assertSame(1, AuditLog::where('action', 'complaints.submitted')->count());
    }

    public function test_a_participant_can_complain_about_their_consultation_but_a_stranger_cannot(): void
    {
        $consultation = $this->scheduledConsultation();

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $this->postJson('/api/v1/complaints', $this->body(['subject_type' => 'consultation', 'subject_id' => $consultation->id]))->assertCreated();

        $this->actingAsRole(RoleName::Patient);
        $this->assertErrorCode(
            $this->postJson('/api/v1/complaints', $this->body(['subject_type' => 'consultation', 'subject_id' => $consultation->id])),
            422,
            'invalid_subject',
        );
    }

    public function test_a_complainant_sees_only_their_own_complaints_and_no_internal_notes(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $id = $this->postJson('/api/v1/complaints', $this->body())->json('data.id');
        $owner = auth('sanctum')->user();

        $this->handler();
        $this->patchJson("/api/v1/admin/complaints/{$id}", ['note' => 'Internal: looks like a known bug.'])->assertOk();

        Sanctum::actingAs($owner, ['*']);
        $response = $this->getJson("/api/v1/complaints/{$id}")->assertOk();
        $this->assertStringNotContainsString('known bug', $response->getContent());

        $this->actingAsRole(RoleName::Patient);
        $this->getJson("/api/v1/complaints/{$id}")->assertForbidden();
        $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_complaints_are_not_available_to_administrators_via_the_user_endpoint(): void
    {
        $this->handler();
        $this->postJson('/api/v1/complaints', $this->body())->assertForbidden();
    }

    public function test_handling_requires_the_permission(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/admin/complaints')->assertForbidden();
    }

    public function test_assigning_starts_the_review_and_closing_needs_a_resolution_and_notifies(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $id = $this->postJson('/api/v1/complaints', $this->body())->json('data.id');
        $complainant = Complaint::findOrFail($id)->complainant;

        $admin = $this->handler();
        $this->patchJson("/api/v1/admin/complaints/{$id}", ['assigned_to_user_id' => $admin->id, 'priority' => 'high'])
            ->assertOk()->assertJsonPath('data.status', 'under_review')->assertJsonPath('data.priority', 'high');

        $this->assertErrorCode($this->patchJson("/api/v1/admin/complaints/{$id}", ['status' => 'resolved']), 422, 'resolution_required');
        $this->patchJson("/api/v1/admin/complaints/{$id}", ['status' => 'resolved', 'resolution' => 'Fixed in the latest release.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->assertErrorCode($this->patchJson("/api/v1/admin/complaints/{$id}", ['priority' => 'low']), 409, 'complaint_closed');
        $this->assertTrue(Notification::where('user_id', $complainant->id)->where('type', 'complaint.updated')->exists());
    }

    public function test_the_assignee_must_be_able_to_handle_complaints(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $id = $this->postJson('/api/v1/complaints', $this->body())->json('data.id');
        $patient = User::factory()->patient()->create();

        $this->handler();
        $this->assertErrorCode($this->patchJson("/api/v1/admin/complaints/{$id}", ['assigned_to_user_id' => $patient->id]), 422, 'invalid_assignee');
    }

    public function test_viewing_a_complaint_as_a_handler_is_audited(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $id = $this->postJson('/api/v1/complaints', $this->body())->json('data.id');

        $this->handler();
        $this->getJson("/api/v1/admin/complaints/{$id}")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'complaints.viewed')->where('subject_id', $id)->exists());
    }

    public function test_validation(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $this->postJson('/api/v1/complaints', $this->body(['subject_type' => 'payment']))->assertUnprocessable();
        $this->postJson('/api/v1/complaints', $this->body(['description' => 'short']))->assertUnprocessable();
        $this->assertErrorCode($this->postJson('/api/v1/complaints', $this->body(['subject_type' => 'payment', 'subject_id' => fake()->uuid()])), 422, 'invalid_subject');
    }
}
