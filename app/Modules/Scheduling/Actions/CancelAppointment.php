<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an appointment and fixes the refund owed under the policy that
 * was snapshotted at booking time:
 *  - cancelled by the doctor, an administrator or the system: full refund;
 *  - cancelled by the patient at least `free_cancellation_hours` before the
 *    start: full refund; later: `late_cancellation_refund_percent`;
 *  - nothing was paid (free, or still awaiting payment): no refund.
 */
class CancelAppointment
{
    public const BY_PATIENT = 'patient';

    public const BY_DOCTOR = 'doctor';

    public const BY_ADMIN = 'admin';

    public const BY_SYSTEM = 'system';

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Appointment $appointment, ?User $actor, string $role, ?string $reason): Appointment
    {
        $appointment = DB::transaction(function () use ($appointment, $actor, $role, $reason) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isCancellable()) {
                throw ApiException::conflict('appointment_not_cancellable', 'This appointment can no longer be cancelled.');
            }

            if ($role === self::BY_PATIENT && $locked->starts_at->isPast()) {
                throw ApiException::conflict('appointment_already_started', 'Appointments cannot be cancelled after their start time.');
            }

            $locked->forceFill([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor?->id,
                'cancelled_by_role' => $role,
                'cancellation_reason' => $reason,
                'refund_percent' => $this->refundPercent($locked, $role),
            ])->save();

            $this->audit->record('appointments.cancelled', $locked, [
                'actor' => $actor,
                'patient_id' => $locked->patient_id,
                'metadata' => ['by' => $role, 'refund_percent' => $locked->refund_percent],
            ]);

            return $locked;
        });

        AppointmentCancelled::dispatch($appointment);

        return $appointment;
    }

    private function refundPercent(Appointment $appointment, string $role): ?int
    {
        if ($appointment->isFree() || $appointment->status === AppointmentStatus::AwaitingPayment) {
            return null;
        }

        if ($role !== self::BY_PATIENT) {
            return 100;
        }

        $hoursBefore = now()->diffInHours($appointment->starts_at, false);
        $policy = $appointment->cancellation_policy;

        return $hoursBefore >= $policy['free_cancellation_hours'] ? 100 : $policy['late_cancellation_refund_percent'];
    }
}
