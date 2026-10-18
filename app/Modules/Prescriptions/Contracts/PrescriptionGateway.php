<?php

namespace App\Modules\Prescriptions\Contracts;

use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Modules\Prescriptions\Models\Prescription;

/**
 * Integration with an authorised Saudi electronic prescribing service.
 *
 * NO real integration exists. The platform must not claim integration with
 * Wasfaty or any national service without an agreement and technical
 * authorisation. Until then only MockPrescriptionGateway is available, and
 * everything it "issues" is flagged as simulated and not legally valid.
 */
interface PrescriptionGateway
{
    public function name(): string;

    public function isSimulated(): bool;

    /**
     * Submits a validated prescription. The service may decide synchronously
     * or later (pending).
     */
    public function submit(Prescription $prescription): GatewayDecision;

    public function cancel(Prescription $prescription, string $reason): void;

    /**
     * Current state at the service (used by pharmacies to verify before dispensing).
     */
    public function status(Prescription $prescription): GatewayDecision;
}
