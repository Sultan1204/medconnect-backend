<?php

namespace App\Modules\Patients;

use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Patients\Listeners\CreatePatientProfile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PatientsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(UserRegistered::class, CreatePatientProfile::class);
    }
}
