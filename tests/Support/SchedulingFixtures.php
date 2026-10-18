<?php

namespace Tests\Support;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Scheduling\Models\AvailabilityRule;
use Carbon\CarbonImmutable;

/**
 * Shared fixtures. The clock is frozen at Sunday 2026-11-01 09:00 Asia/Riyadh
 * (06:00 UTC); the default doctor works Mondays 09:00-12:00 in 30-minute
 * slots with a 10:30-11:00 break.
 */
trait SchedulingFixtures
{
    protected function freezeClock(string $local = '2026-11-01 09:00'): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Riyadh'));
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    protected function scheduledDoctor(array $doctor = []): Doctor
    {
        $doctor = Doctor::factory()->approved()->create($doctor + ['video_fee_amount' => 15000, 'chat_fee_amount' => 0]);

        AvailabilityRule::create([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'breaks' => [['start' => '10:30', 'end' => '11:00']],
            'consultation_types' => ['video', 'chat'],
        ]);

        return $doctor;
    }

    /** Adult patient with every booking consent granted. */
    protected function consentingPatient(?User $user = null): Patient
    {
        $user ??= User::factory()->patient()->create();
        $patient = Patient::where('user_id', $user->id)->first() ?? Patient::factory()->forUser($user)->create(['date_of_birth' => '1990-01-01']);
        $this->grantConsents($patient, $user);

        return $patient;
    }

    protected function grantConsents(Patient $patient, User $by): void
    {
        foreach (config('medconnect.consents.required_for_booking') as $type) {
            PatientConsent::create([
                'patient_id' => $patient->id, 'consent_type' => $type, 'granted' => true,
                'version' => config("medconnect.consents.versions.{$type}"), 'recorded_by_user_id' => $by->id,
            ]);
        }
    }

    /** UTC ISO string for a Riyadh-local time. */
    protected function utc(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Riyadh')->utc()->toIso8601ZuluString();
    }
}
