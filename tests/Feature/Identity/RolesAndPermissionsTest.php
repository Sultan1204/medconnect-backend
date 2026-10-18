<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    public function test_clinical_roles_hold_no_administrative_permissions(): void
    {
        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::PharmacyStaff] as $role) {
            $user = User::factory()->role($role)->create();

            foreach (Permission::cases() as $permission) {
                $this->assertFalse($user->can($permission->value), "{$role->value} must not have {$permission->value}");
            }
        }
    }

    public function test_admins_lack_super_admin_only_permissions(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can(Permission::DoctorsVerify->value));
        $this->assertTrue($admin->can(Permission::AuditView->value));
        $this->assertFalse($admin->can(Permission::AdminsManage->value));
        $this->assertFalse($admin->can(Permission::IntegrationsManage->value));
        $this->assertFalse($admin->can(Permission::SecurityManage->value));
    }

    public function test_super_admin_holds_every_permission(): void
    {
        $super = User::factory()->superAdmin()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($super->can($permission->value));
        }
    }

    public function test_privileged_roles_require_mfa_but_patients_do_not(): void
    {
        $this->assertFalse(User::factory()->patient()->create()->requiresMfa());

        foreach ([RoleName::Doctor, RoleName::PharmacyStaff, RoleName::Admin, RoleName::SuperAdmin] as $role) {
            $this->assertTrue(User::factory()->role($role)->create()->requiresMfa(), $role->value);
        }
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(RoleName::cases()), Role::count());
        $this->assertSame(count(Permission::cases()), \Spatie\Permission\Models\Permission::count());
    }
}
