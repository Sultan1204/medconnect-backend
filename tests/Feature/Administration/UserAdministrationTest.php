<?php

namespace Tests\Feature\Administration;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
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

    public function test_listing_requires_permission_and_masks_contact_details(): void
    {
        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $this->as(User::factory()->admin()->withMfa()->create());
        $target = User::factory()->patient()->create(['phone' => '+966501234567', 'email' => 'someone@example.test']);

        $response = $this->getJson('/api/v1/admin/users?role=patient&q=someone%40example.test')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->assertStringNotContainsString('501234567', $response->getContent());
        $this->assertStringNotContainsString('someone@', $response->getContent());
    }

    public function test_filters_are_validated(): void
    {
        $this->as(User::factory()->admin()->withMfa()->create());
        $this->getJson('/api/v1/admin/users?sort=password')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?role=wizard')->assertUnprocessable();
    }

    public function test_suspending_revokes_sessions_and_is_audited(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $target = User::factory()->patient()->create();
        $target->createToken('t');

        $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Abusive behaviour'])->assertForbidden()->assertJsonPath('error.code', 'step_up_required');

        $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Abusive behaviour'], $this->stepUp($admin))
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'users.suspended')->where('subject_id', $target->id)->exists());
    }

    public function test_reactivation_only_from_suspended_and_clears_lockout(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());
        $active = User::factory()->patient()->create();
        $this->assertErrorCode($this->postJson("/api/v1/admin/users/{$active->id}/reactivate", [], $this->stepUp($admin)), 409, 'invalid_state_transition');

        $suspended = User::factory()->patient()->create(['status' => UserStatus::Suspended, 'failed_login_attempts' => 5, 'locked_until' => now()->addHour()]);
        $this->postJson("/api/v1/admin/users/{$suspended->id}/reactivate", [], $this->stepUp($admin))->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(0, $suspended->refresh()->failed_login_attempts);
        $this->assertNull($suspended->locked_until);
    }

    public function test_guard_rails_on_who_can_act_on_whom(): void
    {
        $admin = $this->as(User::factory()->admin()->withMfa()->create());

        $this->assertErrorCode($this->postJson("/api/v1/admin/users/{$admin->id}/suspend", ['reason' => 'testing'], $this->stepUp($admin)), 422, 'cannot_modify_self');

        $other = User::factory()->admin()->create();
        $this->postJson("/api/v1/admin/users/{$other->id}/suspend", ['reason' => 'testing'], $this->stepUp($admin))->assertForbidden();

        $super = User::factory()->superAdmin()->create();
        $this->postJson("/api/v1/admin/users/{$super->id}/suspend", ['reason' => 'testing'], $this->stepUp($admin))->assertForbidden();

        $root = $this->as(User::factory()->superAdmin()->withMfa()->create());
        $this->postJson("/api/v1/admin/users/{$other->id}/suspend", ['reason' => 'testing'], $this->stepUp($root))->assertOk();
        $this->postJson("/api/v1/admin/users/{$super->id}/suspend", ['reason' => 'testing'], $this->stepUp($root))->assertForbidden();
    }

    public function test_a_suspended_user_cannot_use_the_api(): void
    {
        $user = User::factory()->patient()->create(['status' => UserStatus::Suspended]);
        $this->as($user);
        $this->getJson('/api/v1/complaints')->assertForbidden();
    }
}
