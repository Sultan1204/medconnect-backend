<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after a cancellation commits. The Payments module refunds
 * `refund_percent` of the captured amount.
 */
class AppointmentCancelled
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
