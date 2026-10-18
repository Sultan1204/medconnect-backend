<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

class AppointmentConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
