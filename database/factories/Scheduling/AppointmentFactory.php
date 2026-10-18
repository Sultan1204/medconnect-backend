<?php

namespace Database\Factories\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = now()->addDays(2)->setTimezone('Asia/Riyadh')->setTime(10, 0)->utc();

        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory()->approved(),
            'booked_by_user_id' => fn (array $a) => Patient::find($a['patient_id'])?->user_id,
            'consultation_type' => 'video',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'price_amount' => 15000,
            'currency' => 'SAR',
            'cancellation_policy' => ['free_cancellation_hours' => 24, 'late_cancellation_refund_percent' => 0],
            'confirmed_at' => now(),
        ];
    }

    public function at(\DateTimeInterface $start, int $minutes = 30): static
    {
        return $this->state(['starts_at' => $start, 'ends_at' => CarbonImmutable::instance($start)->addMinutes($minutes)]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function free(): static
    {
        return $this->state(['price_amount' => 0]);
    }
}
