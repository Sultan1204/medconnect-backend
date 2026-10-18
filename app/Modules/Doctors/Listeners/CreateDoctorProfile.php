<?php

namespace App\Modules\Doctors\Listeners;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Events\UserRegistered;

/**
 * A newly registered doctor starts with a `pending` profile and cannot
 * consult until an administrator approves their credentials.
 */
class CreateDoctorProfile
{
    public function handle(UserRegistered $event): void
    {
        if ($event->accountType !== RoleName::Doctor->value) {
            return;
        }

        Doctor::firstOrCreate(
            ['user_id' => $event->user->id],
            ['display_name_en' => $event->user->name],
        );
    }
}
