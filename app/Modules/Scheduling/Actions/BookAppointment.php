<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Books a slot transactionally.
 *
 * Concurrency safety has three layers:
 *  1. the doctor row is locked (SELECT ... FOR UPDATE) so bookings for one
 *     doctor are serialised and availability is re-checked inside the lock;
 *  2. PostgreSQL exclusion constraints reject any overlapping active
 *     appointment for the same doctor or patient;
 *  3. an optional Idempotency-Key makes client retries return the original booking.
 */
class BookAppointment
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{doctor_id: string, patient_id?: string|null, consultation_type: string, starts_at: string}  $data
     */
    public function handle(User $actor, array $data, ?string $idempotencyKey = null): Appointment
    {
        if ($idempotencyKey !== null && ($existing = $this->replay($actor, $idempotencyKey, $data)) !== null) {
            return $existing;
        }

        $patient = isset($data['patient_id'])
            ? Patient::findOrFail($data['patient_id'])
            : Patient::where('user_id', $actor->id)->firstOrFail();

        if (Gate::forUser($actor)->denies('actFor', $patient)) {
            throw ApiException::forbidden('You cannot book appointments for this patient.');
        }

        $missing = $this->consents->missingForBooking($patient);
        if ($missing !== []) {
            throw ApiException::make('consent_required', 'Required consents must be accepted before booking.', 422, ['missing' => $missing]);
        }

        $type = $data['consultation_type'];
        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();

        $doctor = Doctor::publiclyListed()->find($data['doctor_id']);
        if ($doctor === null) {
            throw ApiException::make('doctor_unavailable', 'This doctor is not currently accepting bookings.');
        }

        if ($doctor->user_id === $actor->id || ($patient->user_id !== null && $patient->user_id === $doctor->user_id)) {
            throw ApiException::make('self_booking_not_allowed', 'Doctors cannot book consultations with themselves.');
        }

        $price = $doctor->feeFor($type);
        if ($price === null) {
            throw ApiException::make('consultation_type_not_offered', 'This doctor does not offer this consultation type.');
        }

        $activeCount = Appointment::active()->where('patient_id', $patient->id)->where('ends_at', '>', now())->count();
        if ($activeCount >= (int) config('medconnect.scheduling.max_active_appointments_per_patient')) {
            throw ApiException::make('booking_limit_reached', 'You have reached the maximum number of upcoming appointments.');
        }

        try {
            $appointment = DB::transaction(function () use ($actor, $patient, $doctor, $type, $startsAt, $price, $idempotencyKey) {
                // Serialise bookings for this doctor.
                Doctor::whereKey($doctor->id)->lockForUpdate()->first();

                $slot = $this->availability->find($doctor, $startsAt, $type);
                if ($slot === null) {
                    throw ApiException::conflict('slot_unavailable', 'This time is no longer available. Please choose another slot.');
                }

                $settings = DoctorScheduleSettings::for($doctor);
                $status = match (true) {
                    $price > 0 => AppointmentStatus::AwaitingPayment,
                    $settings->auto_confirm => AppointmentStatus::Confirmed,
                    default => AppointmentStatus::Pending,
                };

                $appointment = Appointment::create([
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'booked_by_user_id' => $actor->id,
                    'consultation_type' => $type,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'status' => $status,
                    'price_amount' => $price,
                    'currency' => $doctor->currency,
                    'cancellation_policy' => $settings->cancellationPolicy(),
                    'payment_due_at' => $status === AppointmentStatus::AwaitingPayment
                        ? now()->addMinutes((int) config('medconnect.scheduling.payment_window_minutes')) : null,
                    'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->audit->record('appointments.booked', $appointment, [
                    'actor' => $actor,
                    'patient_id' => $patient->id,
                    'metadata' => ['status' => $status->value, 'price_amount' => $price, 'type' => $type],
                ]);

                return $appointment;
            });
        } catch (QueryException $e) {
            // 23P01 = exclusion_violation (overlap), 23505 = unique_violation (idempotency race).
            if ($e->getCode() === '23505' && $idempotencyKey !== null && ($existing = $this->replay($actor, $idempotencyKey, $data)) !== null) {
                return $existing;
            }

            if ($e->getCode() === '23P01') {
                throw ApiException::conflict('slot_unavailable', 'This time is no longer available. Please choose another slot.');
            }

            throw $e;
        }

        AppointmentBooked::dispatch($appointment);

        return $appointment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replay(User $actor, string $key, array $data): ?Appointment
    {
        $existing = Appointment::where('booked_by_user_id', $actor->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ($existing->doctor_id !== $data['doctor_id']
            || ! $existing->starts_at->equalTo(CarbonImmutable::parse($data['starts_at'])))) {
            throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
        }

        return $existing;
    }
}
