<?php

namespace Tests\Support;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;

/**
 * Builds consultations directly (bypassing booking) for consultation-phase tests.
 */
trait ConsultationFixtures
{
    protected function scheduledConsultation(string $type = 'video', int $startsInMinutes = 5, ?Doctor $doctor = null, ?Patient $patient = null): Consultation
    {
        $start = now()->addMinutes($startsInMinutes)->startOfMinute();

        $appointment = Appointment::factory()
            ->for($doctor ?? Doctor::factory()->approved()->create(), 'doctor')
            ->for($patient ?? Patient::factory()->create(), 'patient')
            ->at($start)
            ->create(['consultation_type' => $type, 'status' => AppointmentStatus::Confirmed]);

        return app(ConsultationLifecycle::class)->ensureFor($appointment)->load(['appointment', 'doctor.user', 'patient.user']);
    }

    protected function activeConsultation(string $type = 'video', ?Doctor $doctor = null, ?Patient $patient = null): Consultation
    {
        $consultation = $this->scheduledConsultation($type, 5, $doctor, $patient);

        return app(ConsultationLifecycle::class)->start($consultation, $consultation->doctor->user)->load(['appointment', 'doctor.user', 'patient.user']);
    }
}
