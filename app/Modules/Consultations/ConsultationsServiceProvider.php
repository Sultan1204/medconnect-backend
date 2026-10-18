<?php

namespace App\Modules\Consultations;

use App\Modules\Consultations\Console\EnforceConsultationTimeLimits;
use App\Modules\Consultations\Listeners\SyncConsultationWithAppointment;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentConfirmed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ConsultationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([AppointmentBooked::class, AppointmentConfirmed::class, AppointmentCancelled::class], SyncConsultationWithAppointment::class);

        $this->commands([EnforceConsultationTimeLimits::class]);
    }
}
