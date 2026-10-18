<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use SchedulingFixtures;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        $this->doctor = Doctor::factory()->approved()->create();
        $this->patient = Patient::factory()->create();
        Sanctum::actingAs($this->patient->user, ['*']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appointment(string $local = '2026-11-03 10:00', array $attributes = []): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->patient)
            ->at(new \DateTimeImmutable($this->utc($local)))
            ->create($attributes + [
                'booked_by_user_id' => $this->patient->user_id,
                'cancellation_policy' => ['free_cancellation_hours' => 24, 'late_cancellation_refund_percent' => 50],
            ]);
    }

    private function cancel(Appointment $appointment): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$appointment->id}/cancel", ['reason' => 'Schedule conflict']);
    }

    public function test_early_patient_cancellation_is_fully_refundable(): void
    {
        Event::fake([AppointmentCancelled::class]);

        $this->cancel($this->appointment())->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation.by', 'patient')
            ->assertJsonPath('data.cancellation.refund_percent', 100);

        Event::assertDispatched(AppointmentCancelled::class);
    }

    public function test_late_patient_cancellation_uses_the_snapshotted_policy(): void
    {
        $this->cancel($this->appointment('2026-11-01 20:00'))->assertOk()->assertJsonPath('data.cancellation.refund_percent', 50);
    }

    public function test_nothing_is_refunded_when_nothing_was_paid(): void
    {
        $this->cancel($this->appointment(attributes: ['price_amount' => 0]))->assertOk()->assertJsonPath('data.cancellation.refund_percent', null);
        $this->cancel($this->appointment('2026-11-04 10:00', ['status' => AppointmentStatus::AwaitingPayment]))
            ->assertOk()->assertJsonPath('data.cancellation.refund_percent', null);
    }

    public function test_doctor_cancellation_is_always_fully_refunded(): void
    {
        Sanctum::actingAs($this->doctor->user, ['*']);

        $this->cancel($this->appointment('2026-11-01 20:00'))->assertOk()
            ->assertJsonPath('data.cancellation.by', 'doctor')
            ->assertJsonPath('data.cancellation.refund_percent', 100);
    }

    public function test_patients_cannot_cancel_after_the_start_time(): void
    {
        $appointment = $this->appointment('2026-11-01 08:00');

        $this->assertErrorCode($this->cancel($appointment), 409, 'appointment_already_started');
    }

    public function test_finished_appointments_cannot_be_cancelled(): void
    {
        $this->assertErrorCode($this->cancel($this->appointment(attributes: ['status' => AppointmentStatus::Completed])), 409, 'appointment_not_cancellable');
    }

    public function test_unrelated_users_cannot_cancel(): void
    {
        Sanctum::actingAs(User::factory()->patient()->create(), ['*']);

        $this->assertErrorCode($this->cancel($this->appointment()), 403, 'forbidden');
    }

    public function test_doctor_records_no_show_only_after_grace_period(): void
    {
        $appointment = $this->appointment('2026-11-01 08:55');
        Sanctum::actingAs($this->doctor->user, ['*']);

        $this->assertErrorCode($this->postJson("/api/v1/appointments/{$appointment->id}/no-show"), 409, 'too_early_for_no_show');

        $this->travel(11)->minutes();
        $this->postJson("/api/v1/appointments/{$appointment->id}/no-show")->assertOk()->assertJsonPath('data.status', 'no_show');
    }

    public function test_cancellation_is_audited(): void
    {
        $appointment = $this->appointment();
        $this->cancel($appointment)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointments.cancelled', 'subject_id' => $appointment->id, 'patient_id' => $this->patient->id,
        ]);
    }
}
