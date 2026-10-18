<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Non-cancellation status changes: doctor acceptance, payment confirmation,
 * no-shows and expiry of unpaid holds.
 */
class TransitionAppointment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Doctor accepts a booking that requires manual confirmation. */
    public function accept(Appointment $appointment, User $doctorUser): Appointment
    {
        $appointment = $this->move($appointment, [AppointmentStatus::Pending], AppointmentStatus::Confirmed, $doctorUser, 'appointments.accepted', ['confirmed_at' => now()]);
        AppointmentConfirmed::dispatch($appointment);

        return $appointment;
    }

    /**
     * Payment captured (called by the Payments module from a verified webhook).
     * Goes to `confirmed`, or `pending` when the doctor accepts bookings manually.
     */
    public function markPaid(Appointment $appointment): Appointment
    {
        $auto = DoctorScheduleSettings::for($appointment->doctor)->auto_confirm;
        $target = $auto ? AppointmentStatus::Confirmed : AppointmentStatus::Pending;

        $appointment = $this->move($appointment, [AppointmentStatus::AwaitingPayment], $target, null, 'appointments.paid', [
            'confirmed_at' => $auto ? now() : null,
            'payment_due_at' => null,
        ]);

        if ($auto) {
            AppointmentConfirmed::dispatch($appointment);
        }

        return $appointment;
    }

    /** The consultation began (called by the Consultations module). */
    public function markStarted(Appointment $appointment, User $doctorUser): Appointment
    {
        return $this->move($appointment, [AppointmentStatus::Confirmed], AppointmentStatus::InProgress, $doctorUser, 'appointments.started', ['started_at' => now()]);
    }

    /** The consultation was completed (called by the Consultations module). */
    public function markCompleted(Appointment $appointment, User $doctorUser): Appointment
    {
        return $this->move($appointment, [AppointmentStatus::InProgress], AppointmentStatus::Completed, $doctorUser, 'appointments.completed', ['completed_at' => now()]);
    }

    /** Doctor records that the patient did not attend. */
    public function markNoShow(Appointment $appointment, User $doctorUser): Appointment
    {
        $grace = (int) config('medconnect.scheduling.no_show_grace_minutes');

        if ($appointment->starts_at->copy()->addMinutes($grace)->isFuture()) {
            throw ApiException::conflict('too_early_for_no_show', "A no-show can be recorded {$grace} minutes after the start time.");
        }

        return $this->move($appointment, [AppointmentStatus::Confirmed], AppointmentStatus::NoShow, $doctorUser, 'appointments.no_show', ['no_show_at' => now()]);
    }

    /**
     * Releases slots whose payment window elapsed.
     */
    public function expireUnpaid(): int
    {
        $count = 0;

        Appointment::where('status', AppointmentStatus::AwaitingPayment)
            ->where('payment_due_at', '<', now())
            ->each(function (Appointment $appointment) use (&$count) {
                $this->move($appointment, [AppointmentStatus::AwaitingPayment], AppointmentStatus::Expired, null, 'appointments.expired');
                $count++;
            });

        return $count;
    }

    /**
     * @param  list<AppointmentStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function move(Appointment $appointment, array $from, AppointmentStatus $to, ?User $actor, string $action, array $attributes = []): Appointment
    {
        return DB::transaction(function () use ($appointment, $from, $to, $actor, $action, $attributes) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ApiException::conflict('invalid_state_transition', "The appointment is {$locked->status->value}.");
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => $to] + $attributes)->save();

            $this->audit->record($action, $locked, [
                'actor' => $actor,
                'patient_id' => $locked->patient_id,
                'changes' => ['status' => [$previous->value, $to->value]],
            ]);

            return $locked;
        });
    }
}
