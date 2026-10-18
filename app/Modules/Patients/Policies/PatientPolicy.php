<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;

/**
 * Access to patient *profile* data. Clinical data has its own policies that
 * additionally require a care relationship.
 */
class PatientPolicy
{
    public function view(User $user, Patient $patient): bool
    {
        return $this->isSelf($user, $patient)
            || $patient->hasActiveGuardian($user)
            || $user->can(Permission::UsersView->value);
    }

    /**
     * Clinical data from the patient side: the patient or a verified guardian
     * only. Unlike `view`, no administrative permission grants this.
     */
    public function viewClinical(User $user, Patient $patient): bool
    {
        return $this->isSelf($user, $patient) || $patient->hasActiveGuardian($user);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->isSelf($user, $patient) || $patient->hasActiveGuardian($user);
    }

    /**
     * Give consent, book care or otherwise act legally for the patient:
     * an adult patient themselves, or a verified guardian.
     */
    public function actFor(User $user, Patient $patient): bool
    {
        if ($this->isSelf($user, $patient)) {
            return ! $patient->isMinor();
        }

        return $patient->hasActiveGuardian($user);
    }

    private function isSelf(User $user, Patient $patient): bool
    {
        return $patient->user_id !== null && $patient->user_id === $user->id;
    }
}
