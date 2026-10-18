<?php

namespace App\Modules\Pharmacies\Gateways;

use App\Modules\Pharmacies\Contracts\PharmacyGateway;
use App\Modules\Pharmacies\Models\DispenseRequest;

/**
 * No external system: pharmacy staff use the MedConnect pharmacy portal API.
 */
class MockPharmacyGateway implements PharmacyGateway
{
    public function name(): string
    {
        return 'portal';
    }

    public function notify(DispenseRequest $request, string $event): void
    {
        // Intentionally empty - staff see requests in the portal.
    }
}
