<?php

use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Consultations\ConsultationsServiceProvider;
use App\Modules\Doctors\DoctorsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Patients\PatientsServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Pharmacies\PharmaciesServiceProvider;
use App\Modules\Prescriptions\PrescriptionsServiceProvider;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Settlements\SettlementsServiceProvider;
use App\Modules\Video\VideoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    NotificationsServiceProvider::class,
    PatientsServiceProvider::class,
    DoctorsServiceProvider::class,
    SchedulingServiceProvider::class,
    ConsultationsServiceProvider::class,
    MessagingServiceProvider::class,
    VideoServiceProvider::class,
    PaymentsServiceProvider::class,
    SettlementsServiceProvider::class,
    PrescriptionsServiceProvider::class,
    PharmaciesServiceProvider::class,
    AdministrationServiceProvider::class,
];
