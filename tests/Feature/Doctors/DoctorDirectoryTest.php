<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Specialties\Models\Specialty;
use Tests\TestCase;

class DoctorDirectoryTest extends TestCase
{
    private Specialty $derma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->derma = Specialty::factory()->create(['slug' => 'dermatology']);
    }

    /** @return list<string> */
    private function ids(string $query = ''): array
    {
        return array_column($this->getJson('/api/v1/doctors'.$query)->assertOk()->json('data'), 'id');
    }

    public function test_only_eligible_doctors_are_listed(): void
    {
        $listed = Doctor::factory()->approved()->create();
        $pending = Doctor::factory()->create();
        $suspended = Doctor::factory()->status(VerificationStatus::Suspended)->create();
        $expired = Doctor::factory()->approved()->create(['license_expires_on' => now()->subDay()->toDateString()]);
        $notAccepting = Doctor::factory()->approved()->create(['accepting_patients' => false]);
        $noFees = Doctor::factory()->approved()->create(['video_fee_amount' => null, 'chat_fee_amount' => null]);
        $inactiveUser = Doctor::factory()->approved()->create(['user_id' => User::factory()->doctor()->state(['status' => UserStatus::Suspended])]);

        $this->assertSame([$listed->id], $this->ids());

        foreach ([$pending, $suspended, $expired, $notAccepting, $noFees, $inactiveUser] as $doctor) {
            $this->getJson("/api/v1/doctors/{$doctor->id}")->assertNotFound();
        }
    }

    public function test_public_profile_hides_licence_number_and_contact_details(): void
    {
        $doctor = Doctor::factory()->approved()->withSpecialty($this->derma)->create();

        $body = $this->getJson("/api/v1/doctors/{$doctor->id}")->assertOk()
            ->assertJsonPath('data.verification.status', 'verified')
            ->assertJsonPath('data.specialties.0.slug', 'dermatology')
            ->getContent();

        $this->assertStringNotContainsString($doctor->license_number, $body);
        $this->assertStringNotContainsString((string) $doctor->user->phone, $body);
        $this->assertStringNotContainsString((string) $doctor->user->email, $body);
    }

    public function test_filters_by_specialty_language_type_gender_and_price(): void
    {
        $a = Doctor::factory()->approved()->withSpecialty($this->derma)->create([
            'languages' => ['ar'], 'gender' => 'female', 'video_fee_amount' => 10000, 'chat_fee_amount' => null,
        ]);
        $b = Doctor::factory()->approved()->create([
            'languages' => ['en'], 'gender' => 'male', 'video_fee_amount' => null, 'chat_fee_amount' => 5000,
        ]);

        $this->assertSame([$a->id], $this->ids('?specialty=dermatology'));
        $this->assertSame([$a->id], $this->ids('?specialty='.$this->derma->id));
        $this->assertSame([$b->id], $this->ids('?language=en'));
        $this->assertSame([$b->id], $this->ids('?consultation_type=chat'));
        $this->assertSame([$a->id], $this->ids('?gender=female'));
        $this->assertSame([$b->id], $this->ids('?max_price=6000'));
        $this->assertSame([$a->id], $this->ids('?consultation_type=video&min_price=9000&max_price=12000'));
    }

    public function test_name_search_works_in_arabic_and_english_and_escapes_wildcards(): void
    {
        $doctor = Doctor::factory()->approved()->create(['display_name_en' => 'Dr. Sara Alharbi', 'display_name_ar' => 'د. سارة الحربي']);
        Doctor::factory()->approved()->create(['display_name_en' => 'Dr. Omar Nasser', 'display_name_ar' => 'د. عمر ناصر']);

        $this->assertSame([$doctor->id], $this->ids('?q=alhar'));
        $this->assertSame([$doctor->id], $this->ids('?q='.urlencode('سارة')));
        $this->assertSame([], $this->ids('?q='.urlencode('%%')));
    }

    public function test_sorting_by_price(): void
    {
        $cheap = Doctor::factory()->approved()->create(['video_fee_amount' => 5000, 'chat_fee_amount' => null]);
        $mid = Doctor::factory()->approved()->create(['video_fee_amount' => 9000, 'chat_fee_amount' => null]);
        $free = Doctor::factory()->approved()->create(['video_fee_amount' => 0, 'chat_fee_amount' => null]);

        $this->assertSame([$free->id, $cheap->id, $mid->id], $this->ids('?sort=price'));
        $this->assertSame([$mid->id, $cheap->id, $free->id], $this->ids('?sort=-price'));
        $this->getJson("/api/v1/doctors/{$free->id}")->assertJsonPath('data.consultation_types.0.is_free', true);
    }

    public function test_results_are_paginated(): void
    {
        Doctor::factory()->approved()->count(3)->create();

        $this->getJson('/api/v1/doctors?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/doctors?per_page=500')->assertStatus(422);
    }
}
