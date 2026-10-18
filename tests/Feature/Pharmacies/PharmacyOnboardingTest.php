<?php

namespace Tests\Feature\Pharmacies;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Modules\Pharmacies\Models\Pharmacy;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyOnboardingTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->withMfa(self::SECRET)->create();
        Sanctum::actingAs($this->admin, ['*']);
    }

    /** @return array<string, string> */
    private function stepUp(): array
    {
        $this->admin->forceFill(['mfa_last_timestep' => null])->save();

        return ['X-MFA-Code' => app(MfaService::class)->currentCode(self::SECRET)];
    }

    public function test_admin_onboards_a_pharmacy_with_branch_and_staff(): void
    {
        $id = $this->postJson('/api/v1/admin/pharmacies', [
            'name_en' => 'Synthetic Care Pharmacy', 'name_ar' => 'صيدلية الرعاية', 'license_number' => 'MOH-123',
            'licensing_authority' => 'MOH', 'license_expires_on' => now()->addYear()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.verification_status', 'pending')->json('data.id');

        $branchId = $this->postJson("/api/v1/admin/pharmacies/{$id}/branches", [
            'name_en' => 'Olaya', 'name_ar' => 'العليا', 'city' => 'Riyadh', 'address_line' => 'Olaya St',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/pharmacies/{$id}/staff", [
            'name' => 'Synthetic Pharmacist', 'phone' => '0511112222', 'role' => 'pharmacist', 'branch_id' => $branchId,
        ])->assertCreated();

        $staffUser = User::where('phone', '+966511112222')->firstOrFail();
        $this->assertTrue($staffUser->hasRole(RoleName::PharmacyStaff->value));
        $this->assertTrue($staffUser->requiresMfa());
        $this->assertNotNull($this->sms()->lastCodeFor('+966511112222')); // password set-up code

        $this->assertErrorCode($this->postJson("/api/v1/admin/pharmacies/{$id}/verify", ['decision' => 'approve']), 403, 'step_up_required');
        $this->postJson("/api/v1/admin/pharmacies/{$id}/verify", ['decision' => 'approve'], $this->stepUp())
            ->assertOk()->assertJsonPath('data.verification_status', 'approved');
    }

    public function test_duplicate_licences_are_rejected(): void
    {
        Pharmacy::create(['name_en' => 'A', 'name_ar' => 'A', 'license_number' => 'MOH-1', 'licensing_authority' => 'MOH', 'license_expires_on' => now()->addYear()]);

        $this->postJson('/api/v1/admin/pharmacies', [
            'name_en' => 'B', 'name_ar' => 'B', 'license_number' => 'MOH-1', 'licensing_authority' => 'MOH',
            'license_expires_on' => now()->addYear()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_existing_patient_or_doctor_accounts_cannot_become_staff(): void
    {
        $pharmacy = Pharmacy::create(['name_en' => 'A', 'name_ar' => 'A', 'license_number' => 'MOH-2', 'licensing_authority' => 'MOH', 'license_expires_on' => now()->addYear()]);
        User::factory()->patient()->create(['phone' => '+966511113333']);

        $this->assertErrorCode($this->postJson("/api/v1/admin/pharmacies/{$pharmacy->id}/staff", [
            'name' => 'X', 'phone' => '+966511113333', 'role' => 'pharmacist',
        ]), 409, 'account_in_use');
    }

    public function test_expired_licences_cannot_be_approved(): void
    {
        $pharmacy = Pharmacy::create(['name_en' => 'A', 'name_ar' => 'A', 'license_number' => 'MOH-3', 'licensing_authority' => 'MOH', 'license_expires_on' => now()->subDay()]);

        $this->assertErrorCode($this->postJson("/api/v1/admin/pharmacies/{$pharmacy->id}/verify", ['decision' => 'approve'], $this->stepUp()), 422, 'license_expired');
    }

    public function test_only_permitted_admins_manage_pharmacies(): void
    {
        $this->actingAsRole(RoleName::PharmacyStaff);

        $this->assertErrorCode($this->getJson('/api/v1/admin/pharmacies'), 403, 'forbidden');
    }
}
