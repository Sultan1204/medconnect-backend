<?php

namespace App\Modules\Pharmacies;

use App\Modules\Pharmacies\Contracts\PharmacyGateway;
use App\Modules\Pharmacies\Enums\DispenseStatus;
use App\Modules\Pharmacies\Events\DispenseStatusChanged;
use App\Modules\Pharmacies\Gateways\MockPharmacyGateway;
use App\Modules\Pharmacies\Models\DispenseEvent;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Events\PrescriptionStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PharmaciesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PharmacyGateway::class, fn () => match (config('medconnect.drivers.pharmacy')) {
            'mock' => new MockPharmacyGateway,
            default => throw new InvalidArgumentException('Unsupported pharmacy gateway.'),
        });
    }

    public function boot(): void
    {
        // A cancelled or expired prescription withdraws any open pharmacy request.
        Event::listen(function (PrescriptionStatusChanged $event) {
            if (! in_array($event->prescription->status, [PrescriptionStatus::Cancelled, PrescriptionStatus::Expired], true)) {
                return;
            }

            DispenseRequest::where('prescription_id', $event->prescription->id)
                ->whereIn('status', [DispenseStatus::Requested, DispenseStatus::Received, DispenseStatus::UnderReview, DispenseStatus::Ready])
                ->each(function (DispenseRequest $request) use ($event) {
                    $from = $request->status;
                    $request->forceFill(['status' => DispenseStatus::Cancelled])->save();
                    DispenseEvent::create([
                        'dispense_request_id' => $request->id, 'from_status' => $from->value,
                        'to_status' => 'cancelled', 'reason' => 'prescription_'.$event->prescription->status->value,
                    ]);
                    DispenseStatusChanged::dispatch($request, $from, DispenseStatus::Cancelled, null);
                });
        });
    }
}
