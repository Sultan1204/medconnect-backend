<?php

namespace App\Modules\Consultations\Policies;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;

/**
 * Only the two sides of a consultation - the treating doctor and the
 * patient (or verified guardian) - may see or take part in it. No role,
 * including administrator, grants access on its own.
 */
class ConsultationPolicy
{
    public function view(User $user, Consultation $consultation): bool
    {
        return $consultation->participantRole($user) !== null;
    }

    public function participate(User $user, Consultation $consultation): bool
    {
        return $consultation->participantRole($user) !== null;
    }

    /** Start, complete, interrupt, escalate, write clinical notes. */
    public function conduct(User $user, Consultation $consultation): bool
    {
        return $consultation->participantRole($user) === Consultation::ROLE_DOCTOR;
    }

    public function submitIntake(User $user, Consultation $consultation): bool
    {
        return $consultation->participantRole($user) === Consultation::ROLE_PATIENT;
    }
}
