<?php

namespace App\Modules\Pharmacies\Contracts;

use App\Modules\Pharmacies\Models\DispenseRequest;

/**
 * Integration with pharmacy management systems / national dispensing
 * platforms. No real integration exists; dispensing through an
 * unapproved integration must not be enabled.
 */
interface PharmacyGateway
{
    public function name(): string;

    /** Informs the pharmacy system of a new or changed request. */
    public function notify(DispenseRequest $request, string $event): void;
}
