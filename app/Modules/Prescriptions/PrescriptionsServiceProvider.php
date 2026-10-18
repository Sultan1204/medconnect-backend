<?php

namespace App\Modules\Prescriptions;

use App\Modules\Prescriptions\Console\ExpirePrescriptions;
use App\Modules\Prescriptions\Contracts\ClinicalSafetyChecker;
use App\Modules\Prescriptions\Contracts\PrescriptionGateway;
use App\Modules\Prescriptions\Gateways\MockPrescriptionGateway;
use App\Modules\Prescriptions\Safety\MockSafetyChecker;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PrescriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrescriptionGateway::class, fn () => match (config('medconnect.drivers.prescription')) {
            'mock' => new MockPrescriptionGateway,
            // An adapter for an authorised e-prescribing service may be added
            // only with a signed agreement and technical authorisation.
            default => throw new InvalidArgumentException('Unsupported prescription gateway.'),
        });

        $this->app->bind(ClinicalSafetyChecker::class, fn () => match (config('medconnect.drivers.clinical_safety')) {
            'mock' => new MockSafetyChecker,
            default => throw new InvalidArgumentException('A clinically validated safety checker must be configured.'),
        });
    }

    public function boot(): void
    {
        $this->commands([ExpirePrescriptions::class]);
    }
}
