<?php

namespace App\Modules\MedicalRecords\Services;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;

/**
 * Decides whether a doctor may access a patient's clinical record *for a
 * given consultation*. A doctor role alone never grants access: the doctor
 * must be the treating doctor of a non-cancelled consultation, be currently
 * eligible to practise, and be inside the care window
 * [start - pre_access_hours, end + care_access_days].
 */
class CareAccess
{
    public function doctorMayAccess(User $user, Consultation $consultation): bool
    {
        $consultation->loadMissing(['doctor.user', 'appointment']);

        if ($consultation->doctor->user_id !== $user->id || ! $consultation->doctor->canPractice()) {
            return false;
        }

        if ($consultation->status === ConsultationStatus::Cancelled) {
            return false;
        }

        $opens = $consultation->appointment->starts_at->copy()->subHours((int) config('medconnect.medical_records.pre_access_hours'));
        $closes = ($consultation->ended_at ?? $consultation->hardEndsAt())->copy()->addDays((int) config('medconnect.consultations.care_access_days'));

        return now()->between($opens, $closes);
    }

    public function authorize(User $user, Consultation $consultation): void
    {
        if (! $this->doctorMayAccess($user, $consultation)) {
            throw ApiException::forbidden('You do not have a current care relationship for this record.', 'no_care_relationship');
        }
    }
}
