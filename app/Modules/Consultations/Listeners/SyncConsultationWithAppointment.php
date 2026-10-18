<?php

namespace App\Modules\Consultations\Listeners;

use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentConfirmed;

/**
 * A consultation exists for every confirmed appointment and is cancelled
 * with it.
 */
class SyncConsultationWithAppointment
{
    public function __construct(private readonly ConsultationLifecycle $lifecycle) {}

    public function handle(AppointmentBooked|AppointmentConfirmed|AppointmentCancelled $event): void
    {
        $appointment = $event->appointment;

        if ($event instanceof AppointmentCancelled) {
            $this->lifecycle->cancelForAppointment($appointment);

            return;
        }

        if ($appointment->status === AppointmentStatus::Confirmed) {
            $this->lifecycle->ensureFor($appointment);
        }
    }
}
