<?php

namespace Tests\Feature\Administration;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientDataRequest;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function as(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @return array<string, string> */
    private function stepUp(User $user): array
    {
        $user->forceFill(['mfa_last_timestep' => null])->save();

        return ['X-MFA-Code' => app(MfaService::class)->currentCode(self::SECRET)];
    }

    // --- data requests -------------------------------------------------

    private function dataRequest(): PatientDataRequest
    {
        $patient = Patient::factory()->create();

        return PatientDataRequest::create(['patient_id' => $patient->id, 'requested_by_user_id' => $patient->user_id, 'type' => 'access', 'details' => 'Please send me my records.']);
    }

    public function test_data_requests_need_their_own_permission_which_admins_do_not_have_by_default(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $this->getJson('/api/v1/admin/data-requests')->assertForbidden();

        $admin->givePermissionTo('data_requests.manage');
        $this->getJson('/api/v1/admin/data-requests')->assertOk();
    }

    public function test_handling_a_data_request_is_audited_notifies_and_cannot_be_repeated(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $admin->givePermissionTo('data_requests.manage');
        $request = $this->dataRequest();

        $this->getJson("/api/v1/admin/data-requests/{$request->id}")->assertOk()->assertJsonPath('data.details', 'Please send me my records.');
        $this->assertTrue(AuditLog::where('action', 'data_requests.viewed')->exists());

        $this->postJson("/api/v1/admin/data-requests/{$request->id}/handle", ['status' => 'completed'], $this->stepUp($admin))->assertUnprocessable();
        $this->postJson("/api/v1/admin/data-requests/{$request->id}/handle", ['status' => 'completed', 'resolution' => 'Copy provided in person.'], $this->stepUp($admin))
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertTrue(Notification::where('user_id', $request->requested_by_user_id)->where('type', 'data_request.updated')->exists());
        $this->assertErrorCode($this->postJson("/api/v1/admin/data-requests/{$request->id}/handle", ['status' => 'in_progress'], $this->stepUp($admin)), 409, 'data_request_closed');
        $this->assertTrue(AuditLog::where('action', 'data_requests.handled')->where('patient_id', $request->patient_id)->exists());
    }

    public function test_handling_a_data_request_requires_step_up(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $admin->givePermissionTo('data_requests.manage');
        $request = $this->dataRequest();

        $this->postJson("/api/v1/admin/data-requests/{$request->id}/handle", ['status' => 'in_progress'])->assertForbidden();
    }

    // --- audit log -----------------------------------------------------

    public function test_the_audit_log_is_searchable_and_viewing_it_is_audited(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        AuditLog::create(['actor_id' => $admin->id, 'action' => 'payments.refunded', 'outcome' => 'success']);
        AuditLog::create(['actor_id' => $admin->id, 'action' => 'doctors.verified', 'outcome' => 'success']);

        $this->getJson('/api/v1/admin/audit-logs?action=payments.*')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'payments.refunded');
        $this->getJson('/api/v1/admin/audit-logs?action=doctors.verified')->assertOk()->assertJsonCount(1, 'data');
        $this->assertTrue(AuditLog::where('action', 'audit.viewed')->exists());
        $this->getJson('/api/v1/admin/audit-logs?action=bad%20action')->assertUnprocessable();
        $this->getJson('/api/v1/admin/audit-logs?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }

    // --- reports -------------------------------------------------------

    public function test_reports_require_the_permission_and_validate_ranges(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/admin/reports/overview')->assertForbidden();

        $this->as(User::factory()->admin()->withMfa()->create());
        $this->getJson('/api/v1/admin/reports/overview')->assertOk()->assertJsonStructure(['data' => ['range', 'users_by_role', 'appointments_by_status', 'open_complaints']]);
        $this->getJson('/api/v1/admin/reports/appointments')->assertOk()->assertJsonStructure(['data' => ['days']]);
        $this->getJson('/api/v1/admin/reports/financial?from=2026-01-01&to=2026-12-31')->assertOk()->assertJsonPath('data.net', 0);
        $this->getJson('/api/v1/admin/reports/financial?from=2024-01-01&to=2026-12-31')->assertStatus(422);
    }

    // --- settings ------------------------------------------------------

    public function test_settings_are_listed_and_changes_apply_validate_and_audit(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());

        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonFragment(['key' => 'reviews.window_days']);

        $this->putJson('/api/v1/admin/settings/reviews.window_days', ['value' => 14])->assertForbidden();
        $this->putJson('/api/v1/admin/settings/reviews.window_days', ['value' => 14], $this->stepUp($admin))->assertOk()->assertJsonPath('data.value', 14);
        $this->assertSame(14, config('medconnect.reviews.window_days'));
        $this->assertTrue(AuditLog::where('action', 'settings.updated')->exists());

        $this->assertErrorCode($this->putJson('/api/v1/admin/settings/reviews.window_days', ['value' => 1000], $this->stepUp($admin)), 422, 'invalid_setting_value');
        $this->assertErrorCode($this->putJson('/api/v1/admin/settings/reviews.window_days', ['value' => '14'], $this->stepUp($admin)), 422, 'invalid_setting_value');
        $this->assertErrorCode($this->putJson('/api/v1/admin/settings/payments.payouts_enabled', ['value' => true], $this->stepUp($admin)), 404, 'unknown_setting');

        $this->deleteJson('/api/v1/admin/settings/reviews.window_days', [], $this->stepUp($admin))->assertNoContent();
        $this->assertSame(30, config('medconnect.reviews.window_days'));
    }

    public function test_system_tier_settings_need_a_super_administrator(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $this->putJson('/api/v1/admin/settings/payments.commission_bps', ['value' => 1500], $this->stepUp($admin))->assertForbidden();

        $root = $this->as(User::factory()->superAdmin()->withMfa()->create());
        $this->putJson('/api/v1/admin/settings/payments.commission_bps', ['value' => 1500], $this->stepUp($root))->assertOk();
        $this->assertSame(1500, config('medconnect.payments.commission_bps'));
    }

    // --- administrators ------------------------------------------------

    public function test_only_a_super_administrator_manages_admin_permissions(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $this->getJson('/api/v1/admin/administrators')->assertForbidden();

        $root = $this->as(User::factory()->superAdmin()->withMfa()->create());
        $target = User::factory()->admin()->create();

        $this->getJson('/api/v1/admin/administrators')->assertOk();
        $this->putJson("/api/v1/admin/administrators/{$target->id}/permissions", ['permissions' => ['data_requests.manage']])->assertForbidden();

        $this->putJson("/api/v1/admin/administrators/{$target->id}/permissions", ['permissions' => ['data_requests.manage']], $this->stepUp($root))
            ->assertOk()->assertJsonPath('data.extra_permissions.0', 'data_requests.manage');
        $this->assertTrue($target->refresh()->can('data_requests.manage'));
        $this->assertTrue(AuditLog::where('action', 'admins.permissions_changed')->where('subject_id', $target->id)->exists());

        $this->putJson("/api/v1/admin/administrators/{$target->id}/permissions", ['permissions' => []], $this->stepUp($root))->assertOk();
        $this->assertFalse($target->refresh()->can('data_requests.manage'));
    }

    public function test_super_admin_only_permissions_cannot_be_granted_and_targets_are_restricted(): void
    {
        $root = $this->as(User::factory()->superAdmin()->withMfa()->create());
        $target = User::factory()->admin()->create();

        $this->assertErrorCode($this->putJson("/api/v1/admin/administrators/{$target->id}/permissions", ['permissions' => ['admins.manage']], $this->stepUp($root)), 422, 'permission_not_grantable');
        $this->assertErrorCode($this->putJson("/api/v1/admin/administrators/{$root->id}/permissions", ['permissions' => []], $this->stepUp($root)), 422, 'cannot_modify_self');

        $patient = User::factory()->patient()->create();
        $this->assertErrorCode($this->putJson("/api/v1/admin/administrators/{$patient->id}/permissions", ['permissions' => []], $this->stepUp($root)), 422, 'invalid_target');
        $this->putJson("/api/v1/admin/administrators/{$target->id}/permissions", ['permissions' => ['nonsense']], $this->stepUp($root))->assertUnprocessable();
    }
}
