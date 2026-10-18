<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use SchedulingFixtures;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        $this->doctor = $this->scheduledDoctor();
    }

    /** @return list<string> local start times on 2026-11-02 */
    private function mondaySlots(string $query = ''): array
    {
        $slots = $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from=2026-11-02&to=2026-11-02{$query}")
            ->assertOk()->json('data');

        return array_map(fn ($s) => substr($s['local_start'], 11), $slots);
    }

    public function test_slots_follow_rules_and_skip_breaks(): void
    {
        $this->assertSame(['09:00', '09:30', '10:00', '11:00', '11:30'], $this->mondaySlots());

        $first = $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from=2026-11-02&to=2026-11-02")->json('data.0');
        $this->assertSame('2026-11-02T06:00:00Z', $first['starts_at']);
        $this->assertSame(30, $first['duration_minutes']);
        $this->assertSame([['type' => 'video', 'amount' => 15000, 'currency' => 'SAR'], ['type' => 'chat', 'amount' => 0, 'currency' => 'SAR']], $first['fees']);
    }

    public function test_default_range_is_one_week_and_other_days_are_empty(): void
    {
        $response = $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability")->assertOk();

        $this->assertCount(5, $response->json('data')); // only Monday has rules
        $this->assertSame('2026-11-01', $response->json('meta.from'));
        $this->assertSame('2026-11-07', $response->json('meta.to'));
    }

    public function test_time_off_removes_slots_and_extra_windows_add_them(): void
    {
        AvailabilitySlot::create(['doctor_id' => $this->doctor->id, 'type' => 'blocked',
            'starts_at' => $this->utc('2026-11-02 09:00'), 'ends_at' => $this->utc('2026-11-02 10:00')]);
        AvailabilitySlot::create(['doctor_id' => $this->doctor->id, 'type' => 'available',
            'starts_at' => $this->utc('2026-11-02 18:00'), 'ends_at' => $this->utc('2026-11-02 19:00'),
            'slot_minutes' => 60, 'consultation_types' => ['chat']]);

        $this->assertSame(['10:00', '11:00', '11:30', '18:00'], $this->mondaySlots());
        $this->assertSame(['10:00', '11:00', '11:30'], $this->mondaySlots('&consultation_type=video'));
    }

    public function test_booked_slots_and_buffers_are_excluded(): void
    {
        Appointment::factory()->for($this->doctor)->at(new \DateTimeImmutable($this->utc('2026-11-02 09:30')))->create();
        Appointment::factory()->for($this->doctor)->status(AppointmentStatus::Cancelled)
            ->at(new \DateTimeImmutable($this->utc('2026-11-02 11:30')))->create();

        $this->assertSame(['09:00', '10:00', '11:00', '11:30'], $this->mondaySlots());

        DoctorScheduleSettings::create(['doctor_id' => $this->doctor->id, 'buffer_minutes' => 10]);
        // With a 10-minute buffer slots step by 40 minutes and must keep 10 minutes from the 09:30 booking.
        $this->assertSame(['11:00'], $this->mondaySlots());
    }

    public function test_minimum_notice_and_booking_horizon(): void
    {
        $this->freezeClock('2026-11-02 08:45');
        $this->assertSame(['10:00', '11:00', '11:30'], $this->mondaySlots()); // 60 min notice

        DoctorScheduleSettings::create(['doctor_id' => $this->doctor->id, 'max_days_ahead' => 3]);
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from=2026-11-09&to=2026-11-09")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_daily_cap_closes_the_day(): void
    {
        DoctorScheduleSettings::create(['doctor_id' => $this->doctor->id, 'max_daily_appointments' => 1]);
        Appointment::factory()->for($this->doctor)->at(new \DateTimeImmutable($this->utc('2026-11-02 09:00')))->create();

        $this->assertSame([], $this->mondaySlots());
    }

    public function test_range_is_limited_and_unlisted_doctors_are_hidden(): void
    {
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from=2026-11-02&to=2026-11-30")->assertStatus(422);

        $this->doctor->forceFill(['accepting_patients' => false])->save();
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability")->assertNotFound();
    }

    public function test_doctor_manages_weekly_rules(): void
    {
        Sanctum::actingAs($this->doctor->user, ['*']);

        $this->putJson('/api/v1/doctors/me/availability-rules', ['rules' => [
            ['day_of_week' => 2, 'start_time' => '16:00', 'end_time' => '18:00', 'slot_minutes' => 20, 'consultation_types' => ['video']],
            ['day_of_week' => 2, 'start_time' => '19:00', 'end_time' => '21:00', 'slot_minutes' => 20, 'consultation_types' => ['chat']],
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.start_time', '16:00');

        $this->assertSame([], $this->mondaySlots()); // Monday rule replaced

        $this->putJson('/api/v1/doctors/me/availability-rules', ['rules' => [
            ['day_of_week' => 2, 'start_time' => '16:00', 'end_time' => '18:00', 'slot_minutes' => 20, 'consultation_types' => ['video']],
            ['day_of_week' => 2, 'start_time' => '17:00', 'end_time' => '19:00', 'slot_minutes' => 20, 'consultation_types' => ['video']],
        ]])->assertStatus(422);

        $this->putJson('/api/v1/doctors/me/availability-rules', ['rules' => [
            ['day_of_week' => 2, 'start_time' => '18:00', 'end_time' => '16:00', 'slot_minutes' => 20, 'consultation_types' => ['video']],
        ]])->assertStatus(422);
    }

    public function test_doctor_manages_time_off_and_settings(): void
    {
        Sanctum::actingAs($this->doctor->user, ['*']);

        $id = $this->postJson('/api/v1/doctors/me/availability-slots', [
            'type' => 'blocked', 'starts_at' => '2026-11-02T09:00:00+03:00', 'ends_at' => '2026-11-02T12:00:00+03:00', 'note' => 'Conference',
        ])->assertCreated()->json('data.id');
        $this->assertSame([], $this->mondaySlots());

        $this->deleteJson("/api/v1/doctors/me/availability-slots/{$id}")->assertOk();
        $this->assertCount(5, $this->mondaySlots());

        $this->patchJson('/api/v1/doctors/me/schedule-settings', ['free_cancellation_hours' => 12, 'late_cancellation_refund_percent' => 50])
            ->assertOk()->assertJsonPath('data.free_cancellation_hours', 12);
        $this->patchJson('/api/v1/doctors/me/schedule-settings', ['late_cancellation_refund_percent' => 150])->assertStatus(422);
    }

    public function test_directory_filters_by_availability_date(): void
    {
        $idle = Doctor::factory()->approved()->create();

        $monday = array_column($this->getJson('/api/v1/doctors?available_on=2026-11-02')->json('data'), 'id');
        $tuesday = array_column($this->getJson('/api/v1/doctors?available_on=2026-11-03')->json('data'), 'id');

        $this->assertSame([$this->doctor->id], $monday);
        $this->assertSame([], $tuesday);
        $this->assertNotContains($idle->id, $monday);
    }
}
