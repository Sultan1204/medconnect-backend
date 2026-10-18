<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use SchedulingFixtures;

    private Doctor $doctor;

    private User $patientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        $this->doctor = $this->scheduledDoctor();
        $this->patientUser = User::factory()->patient()->create();
        $this->consentingPatient($this->patientUser);
        Sanctum::actingAs($this->patientUser, ['*']);
    }

    private function book(string $local = '2026-11-02 09:00', string $type = 'video', array $extra = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/appointments', [
            'doctor_id' => $this->doctor->id, 'consultation_type' => $type, 'starts_at' => $this->utc($local),
        ] + $extra, $headers);
    }

    public function test_paid_booking_holds_the_slot_awaiting_payment(): void
    {
        Event::fake([AppointmentBooked::class]);

        $this->book()->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.price.amount', 15000)
            ->assertJsonPath('data.price.currency', 'SAR')
            ->assertJsonPath('data.local_start', '2026-11-02T09:00')
            ->assertJsonPath('data.payment_due_at', now()->addMinutes(15)->toIso8601ZuluString())
            ->assertJsonPath('data.cancellation_policy.free_cancellation_hours', 24);

        Event::assertDispatched(AppointmentBooked::class);
        $slots = $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from=2026-11-02&to=2026-11-02")->json('data');
        $this->assertNotContains('2026-11-02T06:00:00Z', array_column($slots, 'starts_at'));
    }

    public function test_free_booking_is_confirmed_immediately(): void
    {
        $this->book(type: 'chat')->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.price.is_free', true);
    }

    public function test_manual_confirmation_requires_the_doctor_to_accept(): void
    {
        DoctorScheduleSettings::create(['doctor_id' => $this->doctor->id, 'auto_confirm' => false]);
        $id = $this->book(type: 'chat')->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->assertErrorCode($this->postJson("/api/v1/appointments/{$id}/accept"), 403, 'forbidden');

        Sanctum::actingAs($this->doctor->user, ['*']);
        $this->postJson("/api/v1/appointments/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_required_consents_must_be_current(): void
    {
        $user = User::factory()->patient()->create();
        Patient::factory()->forUser($user)->create();
        Sanctum::actingAs($user, ['*']);

        $this->assertErrorCode($this->book(), 422, 'consent_required');
    }

    public function test_only_offered_slots_can_be_booked(): void
    {
        $this->assertErrorCode($this->book('2026-11-02 09:10'), 409, 'slot_unavailable');   // off-grid
        $this->assertErrorCode($this->book('2026-11-02 10:30'), 409, 'slot_unavailable');   // break
        $this->assertErrorCode($this->book('2026-11-03 09:00'), 409, 'slot_unavailable');   // no rule that day
    }

    public function test_a_slot_cannot_be_booked_twice(): void
    {
        $this->book()->assertCreated();

        Sanctum::actingAs($other = User::factory()->patient()->create(), ['*']);
        $this->consentingPatient($other);

        $this->assertErrorCode($this->book(), 409, 'slot_unavailable');
        $this->assertSame(1, Appointment::count());
    }

    public function test_patient_cannot_hold_overlapping_appointments_with_different_doctors(): void
    {
        $this->book()->assertCreated();
        $second = $this->doctor;
        $this->doctor = $this->scheduledDoctor();

        $this->assertErrorCode($this->book(), 409, 'slot_unavailable');
        $this->doctor = $second;
    }

    public function test_database_rejects_overlaps_even_without_application_checks(): void
    {
        $existing = Appointment::factory()->for($this->doctor)->at(new \DateTimeImmutable($this->utc('2026-11-02 09:00')))->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('appointments_no_doctor_overlap');

        Appointment::factory()->for($this->doctor)->at(new \DateTimeImmutable($this->utc('2026-11-02 09:15')))->create();
        unset($existing);
    }

    public function test_booking_price_snapshot_is_immutable(): void
    {
        $id = $this->book()->json('data.id');

        $this->doctor->forceFill(['video_fee_amount' => 99900])->save();
        $this->getJson("/api/v1/appointments/{$id}")->assertJsonPath('data.price.amount', 15000);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('snapshot is immutable');
        DB::transaction(fn () => DB::table('appointments')->where('id', $id)->update(['price_amount' => 1]));
    }

    public function test_idempotency_key_returns_the_original_booking(): void
    {
        $headers = ['Idempotency-Key' => 'booking-attempt-0001'];

        $first = $this->book(headers: $headers)->assertCreated()->json('data.id');
        $second = $this->book(headers: $headers)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Appointment::count());

        $this->assertErrorCode($this->book('2026-11-02 11:00', headers: $headers), 409, 'idempotency_key_reused');
    }

    public function test_guardian_books_for_a_verified_dependent_only(): void
    {
        $child = Patient::factory()->dependentOf($this->patientUser, GuardianStatus::Active)->create();
        $this->grantConsents($child, $this->patientUser);
        $pendingChild = Patient::factory()->dependentOf($this->patientUser, GuardianStatus::Pending)->create();

        $this->book(extra: ['patient_id' => $child->id])->assertCreated()->assertJsonPath('data.patient.id', $child->id);
        $this->assertErrorCode($this->book('2026-11-02 11:00', extra: ['patient_id' => $pendingChild->id]), 403, 'forbidden');

        $stranger = Patient::factory()->create();
        $this->assertErrorCode($this->book('2026-11-02 11:30', extra: ['patient_id' => $stranger->id]), 403, 'forbidden');
    }

    public function test_doctors_cannot_book_and_unlisted_doctors_cannot_be_booked(): void
    {
        Sanctum::actingAs($this->doctor->user, ['*']);
        $this->assertErrorCode($this->book(), 403, 'forbidden');

        Sanctum::actingAs($this->patientUser, ['*']);
        $this->doctor->forceFill(['verification_status' => 'suspended'])->save();
        $this->assertErrorCode($this->book(), 422, 'doctor_unavailable');
    }

    public function test_upcoming_booking_limit(): void
    {
        config(['medconnect.scheduling.max_active_appointments_per_patient' => 1]);
        $this->book()->assertCreated();

        $this->assertErrorCode($this->book('2026-11-02 11:00'), 422, 'booking_limit_reached');
    }

    public function test_unpaid_holds_expire_and_free_the_slot(): void
    {
        $id = $this->book()->json('data.id');

        $this->travel(16)->minutes();
        $this->artisan('appointments:expire-unpaid')->assertSuccessful();

        $this->assertSame(AppointmentStatus::Expired, Appointment::findOrFail($id)->status);
        Sanctum::actingAs($other = User::factory()->patient()->create(), ['*']);
        $this->consentingPatient($other);
        $this->book()->assertCreated();
    }

    public function test_listing_is_scoped_to_the_patient_and_the_doctor(): void
    {
        $this->book()->assertCreated();
        Appointment::factory()->create(); // someone else's

        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->doctor->user, ['*']);
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.full_name', Patient::where('user_id', $this->patientUser->id)->value('full_name'));
    }

    public function test_appointments_are_not_visible_to_unrelated_users(): void
    {
        $id = $this->book()->json('data.id');

        Sanctum::actingAs(User::factory()->patient()->create(), ['*']);
        $this->assertErrorCode($this->getJson("/api/v1/appointments/{$id}"), 403, 'forbidden');

        $this->actingAsRole(RoleName::Doctor);
        $this->assertErrorCode($this->getJson("/api/v1/appointments/{$id}"), 403, 'forbidden');

        $this->actingAsRole(RoleName::Admin);
        $this->getJson("/api/v1/appointments/{$id}")->assertOk();
        $this->getJson('/api/v1/admin/appointments')->assertOk()->assertJsonCount(1, 'data');
    }
}
