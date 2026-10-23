<?php

namespace Tests\Feature\Specialties;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Specialties\Models\Specialty;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SpecialtyTest extends TestCase
{
    public function test_public_list_contains_only_active_specialties(): void
    {
        $this->seed(SpecialtySeeder::class);
        Specialty::factory()->create(['is_active' => false, 'slug' => 'hidden']);

        $response = $this->getJson('/api/v1/specialties')->assertOk()->assertJsonCount(10, 'data');
        $this->assertNotContains('hidden', array_column($response->json('data'), 'slug'));
    }

    public function test_cached_list_survives_a_serializing_cache_store(): void
    {
        // Redis/file stores serialize values; the array store only does with this flag.
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        Specialty::factory()->create(['name_en' => 'Cardiology', 'name_ar' => 'القلب', 'is_active' => true]);

        $this->getJson('/api/v1/specialties')->assertOk();
        $this->getJson('/api/v1/specialties', ['Accept-Language' => 'ar'])->assertOk()->assertJsonFragment(['name' => 'القلب']);
    }

    public function test_names_are_localized_by_accept_language(): void
    {
        $this->seed(SpecialtySeeder::class);

        $this->getJson('/api/v1/specialties', ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.0.name', 'طب الأسرة')
            ->assertJsonPath('data.0.name_en', 'Family Medicine')
            ->assertHeader('Content-Language', 'ar');

        $this->getJson('/api/v1/specialties', ['Accept-Language' => 'en'])->assertJsonPath('data.0.name', 'Family Medicine');
    }

    public function test_admin_manages_specialties_and_cache_is_refreshed(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->getJson('/api/v1/specialties')->assertJsonCount(0, 'data');

        $id = $this->postJson('/api/v1/admin/specialties', ['slug' => 'urology', 'name_en' => 'Urology', 'name_ar' => 'المسالك البولية'])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v1/specialties')->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/admin/specialties/{$id}", ['is_active' => false])->assertOk();
        $this->getJson('/api/v1/specialties')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'specialties.updated', 'subject_id' => $id]);
    }

    public function test_slug_must_be_unique_and_well_formed(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Specialty::factory()->create(['slug' => 'urology']);

        $this->postJson('/api/v1/admin/specialties', ['slug' => 'urology', 'name_en' => 'U', 'name_ar' => 'U'])->assertStatus(422);
        $this->postJson('/api/v1/admin/specialties', ['slug' => 'Bad Slug!', 'name_en' => 'U', 'name_ar' => 'U'])->assertStatus(422);
    }

    public function test_non_admins_cannot_manage_specialties(): void
    {
        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::PharmacyStaff] as $role) {
            $this->actingAsRole($role);
            $this->assertErrorCode($this->postJson('/api/v1/admin/specialties', ['slug' => 'x', 'name_en' => 'X', 'name_ar' => 'X']), 403, 'forbidden');
        }
    }
}
