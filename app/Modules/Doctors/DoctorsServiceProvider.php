<?php

namespace App\Modules\Doctors;

use App\Modules\Doctors\Console\ExpireDoctorLicenses;
use App\Modules\Doctors\Contracts\ProfessionalVerifier;
use App\Modules\Doctors\Listeners\CreateDoctorProfile;
use App\Modules\Doctors\Verification\ManualProfessionalVerifier;
use App\Modules\Identity\Events\UserRegistered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DoctorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProfessionalVerifier::class, fn () => match (config('medconnect.drivers.professional_verification')) {
            'manual' => new ManualProfessionalVerifier,
            default => throw new InvalidArgumentException('Unsupported professional verification driver.'),
        });
    }

    public function boot(): void
    {
        Event::listen(UserRegistered::class, CreateDoctorProfile::class);

        $this->commands([ExpireDoctorLicenses::class]);
    }
}
