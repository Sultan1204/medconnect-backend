<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;

/**
 * Appointment access is limited to the patient side (the patient or a
 * verified guardian), the treating doctor, and monitoring administrators.
 */
class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isPatientSide($user, $appointment)
            || $this->isDoctor($user, $appointment)
            || $user->can(Permission::AppointmentsMonitor->value);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->isPatientSide($user, $appointment)
            || $this->isDoctor($user, $appointment)
            || $user->can(Permission::AppointmentsMonitor->value);
    }

    /** Accept/decline pending bookings, mark no-shows. */
    public function manageAsDoctor(User $user, Appointment $appointment): bool
    {
        return $this->isDoctor($user, $appointment);
    }

    public function isPatientSide(User $user, Appointment $appointment): bool
    {
        return $user->can('actFor', $appointment->patient);
    }

    public function isDoctor(User $user, Appointment $appointment): bool
    {
        return $appointment->doctor->user_id === $user->id;
    }
}
