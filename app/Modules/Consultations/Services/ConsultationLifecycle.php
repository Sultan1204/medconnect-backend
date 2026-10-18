<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus as Status;
use App\Modules\Consultations\Events\ConsultationCompleted;
use App\Modules\Consultations\Events\ConsultationStatusChanged;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Actions\TransitionAppointment;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 *   scheduled ──start──► active ──complete──► completed
 *       │                 │  ▲
 *    (appointment       interrupt│resume
 *     cancelled)          ▼  │
 *       ▼              interrupted ──complete──► completed
 *   cancelled
 */
class ConsultationLifecycle
{
    public function __construct(
        private readonly TransitionAppointment $appointments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Idempotently creates the consultation for a confirmed appointment.
     */
    public function ensureFor(Appointment $appointment): Consultation
    {
        return Consultation::firstOrCreate(['appointment_id' => $appointment->id], [
            'patient_id' => $appointment->patient_id,
            'doctor_id' => $appointment->doctor_id,
            'consultation_type' => $appointment->consultation_type,
            'status' => Status::Scheduled,
            'max_duration_minutes' => (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)
                + (int) config('medconnect.consultations.overtime_grace_minutes'),
        ]);
    }

    public function start(Consultation $consultation, User $doctorUser): Consultation
    {
        if (! $consultation->doctor->canPractice()) {
            throw ApiException::forbidden('Your professional verification does not currently allow consultations.', 'doctor_not_eligible');
        }

        $resuming = $consultation->status === Status::Interrupted;

        if (! in_array($consultation->status, [Status::Scheduled, Status::Interrupted], true)) {
            throw ApiException::conflict('invalid_state_transition', "The consultation is {$consultation->status->value}.");
        }

        $appointment = $consultation->appointment;
        $opensAt = $appointment->starts_at->copy()->subMinutes((int) config('medconnect.consultations.early_start_minutes'));

        if (now()->lt($opensAt)) {
            throw ApiException::conflict('consultation_not_open', 'The consultation cannot be started yet.');
        }

        if (now()->gt($consultation->hardEndsAt())) {
            throw ApiException::conflict('consultation_window_closed', 'The booked time for this consultation has passed.');
        }

        return $this->transition($consultation, Status::Active, $doctorUser, $resuming ? 'consultations.resumed' : 'consultations.started', function (Consultation $c) use ($appointment, $doctorUser, $resuming) {
            if (! $resuming) {
                $c->started_at = now();
                $this->appointments->markStarted($appointment, $doctorUser);
            }
            $c->interrupted_at = null;
        });
    }

    public function complete(Consultation $consultation, User $doctorUser): Consultation
    {
        if (! in_array($consultation->status, [Status::Active, Status::Interrupted], true)) {
            throw ApiException::conflict('invalid_state_transition', "The consultation is {$consultation->status->value}.");
        }

        $consultation = $this->transition($consultation, Status::Completed, $doctorUser, 'consultations.completed', function (Consultation $c) use ($doctorUser) {
            $c->ended_at = now();
            $this->appointments->markCompleted($c->appointment, $doctorUser);
        });

        ConsultationCompleted::dispatch($consultation);

        return $consultation;
    }

    /**
     * Technical failure or the hard time limit was reached. The doctor may
     * resume (within the window) or complete afterwards to write notes.
     */
    public function interrupt(Consultation $consultation, ?User $actor, string $reason): Consultation
    {
        if ($consultation->status !== Status::Active) {
            throw ApiException::conflict('invalid_state_transition', "The consultation is {$consultation->status->value}.");
        }

        return $this->transition($consultation, Status::Interrupted, $actor, 'consultations.interrupted', function (Consultation $c) {
            $c->interrupted_at = now();
        }, ['reason' => $reason]);
    }

    /**
     * Mirrors a cancelled appointment.
     */
    public function cancelForAppointment(Appointment $appointment): void
    {
        $consultation = Consultation::where('appointment_id', $appointment->id)->first();

        if ($consultation !== null && $consultation->status === Status::Scheduled) {
            $this->transition($consultation, Status::Cancelled, null, 'consultations.cancelled');
        }
    }

    /**
     * Scheduled: active consultations past their hard end are interrupted
     * (video rooms close); the doctor can still complete and document.
     */
    public function enforceTimeLimits(): int
    {
        $count = 0;

        Consultation::query()
            ->where('status', Status::Active)
            ->whereHas('appointment', fn ($q) => $q->where('ends_at', '<', now()->subMinutes((int) config('medconnect.consultations.overtime_grace_minutes'))))
            ->with(['appointment', 'doctor', 'patient'])
            ->each(function (Consultation $consultation) use (&$count) {
                $this->interrupt($consultation, null, 'time_limit_reached');
                $count++;
            });

        return $count;
    }

    /**
     * @param  (callable(Consultation): void)|null  $mutate
     * @param  array<string, mixed>  $metadata
     */
    private function transition(Consultation $consultation, Status $to, ?User $actor, string $action, ?callable $mutate = null, array $metadata = []): Consultation
    {
        $consultation = DB::transaction(function () use ($consultation, $to, $actor, $action, $mutate, $metadata) {
            /** @var Consultation $locked */
            $locked = Consultation::whereKey($consultation->id)->lockForUpdate()->with(['appointment', 'doctor', 'patient'])->firstOrFail();
            $from = $locked->status;

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->status = $to;
            $locked->save();

            $this->audit->record($action, $locked, [
                'actor' => $actor,
                'patient_id' => $locked->patient_id,
                'purpose' => 'consultation:'.$locked->id,
                'changes' => ['status' => [$from->value, $to->value]],
                'metadata' => $metadata ?: null,
            ]);

            return $locked;
        });

        ConsultationStatusChanged::dispatch($consultation);

        return $consultation;
    }
}
