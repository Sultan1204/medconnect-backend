<?php

namespace App\Modules\Doctors\Policies;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;

class DoctorPolicy
{
    /** Licence details, credentials and verification history. */
    public function viewPrivate(User $user, Doctor $doctor): bool
    {
        return $this->owns($user, $doctor) || $user->can(Permission::DoctorsVerify->value);
    }

    public function update(User $user, Doctor $doctor): bool
    {
        return $this->owns($user, $doctor);
    }

    public function verify(User $user, Doctor $doctor): bool
    {
        // Administrators may not verify their own doctor profile.
        return $user->can(Permission::DoctorsVerify->value) && ! $this->owns($user, $doctor);
    }

    private function owns(User $user, Doctor $doctor): bool
    {
        return $doctor->user_id === $user->id;
    }
}
