<?php

namespace Tests\Feature\Notifications;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = Doctor::factory()->approved()->create();
        $this->patient = Patient::factory()->create();
    }

    private function appointment(string $startsIn, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        return Appointment::factory()->for($this->doctor, 'doctor')->for($this->patient, 'patient')
            ->at(now()->add($startsIn))->status($status)->create();
    }

    private function remind(): void
    {
        $this->artisan('notifications:send-reminders')->assertSuccessful();
    }

    private function reminders(): int
    {
        return Notification::where('type', 'appointment.reminder')->count();
    }

    public function test_the_day_before_window_reminds_patient_and_doctor_once(): void
    {
        $this->appointment('23 hours 30 minutes');

        $this->remind();
        $this->assertSame(2, $this->reminders());
        $this->assertDatabaseHas('notifications', ['user_id' => $this->patient->user_id, 'type' => 'appointment.reminder']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->doctor->user_id, 'type' => 'appointment.reminder']);

        $this->remind(); // scheduler overlap or re-run
        $this->assertSame(2, $this->reminders());
    }

    public function test_the_one_hour_reminder_is_separate_from_the_day_before_reminder(): void
    {
        $appointment = $this->appointment('23 hours 30 minutes');
        $this->remind();

        $this->travelTo(now()->addHours(22)->addMinutes(45)); // now 45 minutes before the start
        $this->assertTrue($appointment->starts_at->isBefore(now()->addHour()));
        $this->remind();

        $this->assertSame(4, $this->reminders());
        $this->remind();
        $this->assertSame(4, $this->reminders());
    }

    public function test_appointments_outside_the_windows_or_not_confirmed_are_ignored(): void
    {
        $this->appointment('20 hours');
        $this->appointment('3 days');
        $this->appointment('23 hours 30 minutes', AppointmentStatus::Cancelled);
        $this->appointment('30 minutes', AppointmentStatus::Pending);

        $this->remind();

        $this->assertSame(0, $this->reminders());
    }

    public function test_a_late_booking_only_receives_the_nearest_reminder(): void
    {
        $this->appointment('50 minutes');

        $this->remind();

        $this->assertSame(2, $this->reminders());
        $this->assertSame(0, Notification::where('dedupe_key', 'like', '%:24h:%')->count());
    }
}
