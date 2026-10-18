<?php

namespace App\Modules\Patients\Listeners;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Patients\Models\Patient;

/**
 * Creates (or refreshes, for a re-claimed unverified registration) the
 * patient profile of a self-registered patient.
 */
class CreatePatientProfile
{
    public function handle(UserRegistered $event): void
    {
        if ($event->accountType !== RoleName::Patient->value) {
            return;
        }

        Patient::updateOrCreate(
            ['user_id' => $event->user->id],
            array_filter([
                'full_name' => $event->user->name,
                'date_of_birth' => $event->profile['date_of_birth'] ?? null,
                'gender' => $event->profile['gender'] ?? null,
            ], fn ($v) => $v !== null),
        );
    }
}
