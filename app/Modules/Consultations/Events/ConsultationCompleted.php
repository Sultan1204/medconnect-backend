<?php

namespace App\Modules\Consultations\Events;

use App\Modules\Consultations\Models\Consultation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after completion commits. Payments records doctor earnings;
 * Reviews opens the review window; Video ends the room.
 */
class ConsultationCompleted
{
    use Dispatchable;

    public function __construct(public readonly Consultation $consultation) {}
}
