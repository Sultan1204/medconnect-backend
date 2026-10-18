<?php

namespace App\Modules\Scheduling;

use App\Modules\Doctors\Search\DoctorAvailabilityFilter;
use App\Modules\Scheduling\Console\ExpireUnpaidAppointments;
use App\Modules\Scheduling\Search\ScheduledAvailabilityFilter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DoctorAvailabilityFilter::class, ScheduledAvailabilityFilter::class);
    }

    public function boot(): void
    {
        $this->commands([ExpireUnpaidAppointments::class]);

        // Slot-holding requests are limited to stop one account from blocking a doctor's calendar.
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(10)->by('booking:'.$request->user()?->getAuthIdentifier()));
    }
}
