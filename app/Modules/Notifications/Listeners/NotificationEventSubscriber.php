<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Events\ConsultationStatusChanged;
use App\Modules\Consultations\Events\EmergencyEscalated;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Events\SuspiciousActivityDetected;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Events\MessageSent;
use App\Modules\Notifications\Enums\NotificationType as Type;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Pharmacies\Enums\DispenseStatus;
use App\Modules\Pharmacies\Events\DispenseStatusChanged;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Events\PrescriptionStatusChanged;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns domain events into notifications.
 *
 * Failing to notify must never break the business action that raised the
 * event (booking, payment webhook, prescription...). Each handler therefore
 * runs in a savepoint and reports - rather than throws - its errors.
 *
 * Texts are generic by design (see lang/*\/notifications.php); only
 * identifiers and non-clinical values are passed as parameters.
 */
class NotificationEventSubscriber
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(AppointmentBooked::class, [$this, 'appointmentBooked']);
        $events->listen(AppointmentConfirmed::class, [$this, 'appointmentConfirmed']);
        $events->listen(AppointmentCancelled::class, [$this, 'appointmentCancelled']);
        $events->listen(PaymentCaptured::class, [$this, 'paymentCaptured']);
        $events->listen(RefundSucceeded::class, [$this, 'refundSucceeded']);
        $events->listen(MessageSent::class, [$this, 'messageSent']);
        $events->listen(ConsultationStatusChanged::class, [$this, 'consultationStatusChanged']);
        $events->listen(EmergencyEscalated::class, [$this, 'emergencyEscalated']);
        $events->listen(PrescriptionStatusChanged::class, [$this, 'prescriptionStatusChanged']);
        $events->listen(DispenseStatusChanged::class, [$this, 'dispenseStatusChanged']);
        $events->listen(SuspiciousActivityDetected::class, [$this, 'suspiciousActivity']);
    }

    public function appointmentBooked(AppointmentBooked $event): void
    {
        $this->safely(function () use ($event) {
            $appointment = $event->appointment->loadMissing(['doctor.user', 'patient.user', 'bookedBy']);

            // Paid bookings stay silent until payment confirms them.
            if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                $this->newBooking($appointment);
            }

            if ($appointment->status === AppointmentStatus::Confirmed) {
                $this->confirmed($appointment);
            }
        });
    }

    public function appointmentConfirmed(AppointmentConfirmed $event): void
    {
        $this->safely(function () use ($event) {
            $appointment = $event->appointment->loadMissing(['doctor.user', 'patient.user', 'bookedBy']);

            $this->newBooking($appointment); // de-duplicated if the doctor was already told
            $this->confirmed($appointment);
        });
    }

    public function appointmentCancelled(AppointmentCancelled $event): void
    {
        $this->safely(function () use ($event) {
            $appointment = $event->appointment->loadMissing(['doctor.user', 'patient.user', 'bookedBy']);
            $params = $this->appointmentParams($appointment);

            foreach ([$this->patientUser($appointment->patient, $appointment->bookedBy), $appointment->doctor->user] as $user) {
                // The person who cancelled does not need to be told.
                if ($user !== null && $user->id !== $appointment->cancelled_by_user_id) {
                    $this->notifications->notify($user, Type::AppointmentCancelled, $params, "appointment-cancelled:{$appointment->id}:{$user->id}");
                }
            }
        });
    }

    public function paymentCaptured(PaymentCaptured $event): void
    {
        $this->safely(function () use ($event) {
            $payment = $event->payment;
            $payer = User::find($payment->payer_user_id);

            if ($payer !== null) {
                $this->notifications->notify($payer, Type::PaymentConfirmed, [
                    'payment_id' => $payment->id, 'appointment_id' => $payment->appointment_id,
                    'amount' => $payment->amount, 'currency' => $payment->currency,
                ], "payment-captured:{$payment->id}");
            }
        });
    }

    public function refundSucceeded(RefundSucceeded $event): void
    {
        $this->safely(function () use ($event) {
            $refund = $event->refund->loadMissing('payment');
            $payer = User::find($refund->payment->payer_user_id);

            if ($payer !== null) {
                $this->notifications->notify($payer, Type::RefundSucceeded, [
                    'refund_id' => $refund->id, 'payment_id' => $refund->payment_id,
                    'amount' => $refund->amount, 'currency' => $refund->currency,
                ], "refund-succeeded:{$refund->id}");
            }
        });
    }

    public function messageSent(MessageSent $event): void
    {
        $this->safely(function () use ($event) {
            $message = $event->message;
            $consultation = $message->consultation()->with(['doctor.user', 'patient.user', 'appointment.bookedBy'])->firstOrFail();

            $recipient = $message->sender_role === Consultation::ROLE_PATIENT
                ? $consultation->doctor->user
                : $this->patientUser($consultation->patient, $consultation->appointment->bookedBy);

            if ($recipient === null || $recipient->id === $message->sender_user_id) {
                return;
            }

            // One alert per consultation per five minutes, however many messages arrive.
            $bucket = intdiv(now()->getTimestamp(), 300);
            $this->notifications->notify($recipient, Type::MessageReceived, ['consultation_id' => $consultation->id], "message:{$consultation->id}:{$bucket}");
        });
    }

    public function consultationStatusChanged(ConsultationStatusChanged $event): void
    {
        $this->safely(function () use ($event) {
            if ($event->consultation->status !== ConsultationStatus::Active) {
                return;
            }

            $consultation = $event->consultation->loadMissing(['patient.user', 'appointment.bookedBy']);
            $patientUser = $this->patientUser($consultation->patient, $consultation->appointment->bookedBy);

            if ($patientUser !== null) {
                $this->notifications->notify($patientUser, Type::ConsultationStarted, ['consultation_id' => $consultation->id], "consultation-started:{$consultation->id}");
            }
        });
    }

    public function emergencyEscalated(EmergencyEscalated $event): void
    {
        $this->safely(function () use ($event) {
            // Screening escalations are shown to the patient in the API response itself.
            if ($event->escalation->source !== 'doctor') {
                return;
            }

            $consultation = $event->escalation->consultation()->with(['patient.user', 'appointment.bookedBy'])->firstOrFail();
            $patientUser = $this->patientUser($consultation->patient, $consultation->appointment->bookedBy);

            if ($patientUser !== null) {
                $this->notifications->notify($patientUser, Type::ConsultationEmergency, ['consultation_id' => $consultation->id], "emergency:{$event->escalation->id}");
            }
        });
    }

    public function prescriptionStatusChanged(PrescriptionStatusChanged $event): void
    {
        $this->safely(function () use ($event) {
            $prescription = $event->prescription;

            $notifiable = [PrescriptionStatus::Issued, PrescriptionStatus::Rejected, PrescriptionStatus::Cancelled, PrescriptionStatus::Expired, PrescriptionStatus::Dispensed];
            if (! in_array($prescription->status, $notifiable, true) || $event->from === $prescription->status) {
                return;
            }

            $prescription->loadMissing(['patient.user', 'consultation.appointment.bookedBy']);
            $patientUser = $this->patientUser($prescription->patient, $prescription->consultation->appointment->bookedBy);

            if ($patientUser !== null) {
                // The medication names are deliberately not part of the notification.
                $this->notifications->notify($patientUser, Type::PrescriptionStatusChanged, [
                    'prescription_id' => $prescription->id, 'status' => $prescription->status->value,
                ], "prescription:{$prescription->id}:{$prescription->status->value}");
            }
        });
    }

    public function dispenseStatusChanged(DispenseStatusChanged $event): void
    {
        $this->safely(function () use ($event) {
            $request = $event->request->loadMissing('branch');

            if ($event->to === DispenseStatus::Requested) {
                $staff = PharmacyStaff::where('is_active', true)
                    ->where(fn ($q) => $q->where('branch_id', $request->branch_id)
                        ->orWhere(fn ($q) => $q->whereNull('branch_id')->where('pharmacy_id', $request->branch->pharmacy_id)))
                    ->with('user')
                    ->get();

                foreach ($staff as $member) {
                    $this->notifications->notify($member->user, Type::PharmacyRequestReceived, ['dispense_request_id' => $request->id], "dispense-received:{$request->id}:{$member->user_id}");
                }

                return;
            }

            // The requester (patient or guardian) hears about changes made by someone else.
            $requester = User::find($request->requested_by_user_id);

            if ($requester !== null && $requester->id !== $event->actor?->id) {
                $this->notifications->notify($requester, Type::PharmacyRequestUpdated, [
                    'dispense_request_id' => $request->id, 'status' => $event->to->value,
                ], "dispense:{$request->id}:{$event->to->value}");
            }
        });
    }

    public function suspiciousActivity(SuspiciousActivityDetected $event): void
    {
        $this->safely(function () use ($event) {
            if ($event->type === SuspiciousActivityDetected::NEW_DEVICE) {
                $platform = (string) ($event->context['platform'] ?? 'unknown');
                $this->notifications->notify($event->user, Type::SecurityNewDevice, ['platform' => $platform], "new-device:{$event->user->id}:".now()->format('Ymd').":{$platform}");

                return;
            }

            if ($event->type === SuspiciousActivityDetected::ACCOUNT_LOCKED) {
                $bucket = intdiv(now()->getTimestamp(), 900);
                $this->notifications->notify($event->user, Type::SecurityAccountLocked, [], "account-locked:{$event->user->id}:{$bucket}");
            }
        });
    }

    private function newBooking(Appointment $appointment): void
    {
        $doctorUser = $appointment->doctor->user;

        if ($doctorUser !== null) {
            $this->notifications->notify($doctorUser, Type::AppointmentNewBooking, $this->appointmentParams($appointment), "appointment-new:{$appointment->id}");
        }
    }

    private function confirmed(Appointment $appointment): void
    {
        $patientUser = $this->patientUser($appointment->patient, $appointment->bookedBy);

        if ($patientUser !== null) {
            $this->notifications->notify($patientUser, Type::AppointmentConfirmed, $this->appointmentParams($appointment), "appointment-confirmed:{$appointment->id}");
        }
    }

    /** @return array<string, string> */
    private function appointmentParams(Appointment $appointment): array
    {
        return [
            'appointment_id' => $appointment->id,
            'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
        ];
    }

    /**
     * The login that should hear about a patient's care: the patient's own
     * account or, for a dependant, whoever acted for them.
     */
    private function patientUser(Patient $patient, ?User $fallback): ?User
    {
        return $patient->user ?? $fallback;
    }

    private function safely(callable $handler): void
    {
        try {
            DB::transaction(fn () => $handler()); // savepoint when nested: a failure cannot poison the caller's transaction
        } catch (Throwable $e) {
            report($e);
        }
    }
}
