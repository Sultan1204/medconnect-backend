<?php

namespace App\Modules\Consultations\Events;

use App\Modules\Consultations\Models\ConsultationEscalation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifications sends emergency instructions to the patient and alerts the
 * doctor and the operations team. Delivery must never wait on a doctor.
 */
class EmergencyEscalated
{
    use Dispatchable;

    public function __construct(public readonly ConsultationEscalation $escalation) {}
}
