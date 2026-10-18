<?php

namespace App\Modules\Prescriptions\Gateways;

use App\Modules\Prescriptions\Contracts\PrescriptionGateway;
use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Models\Prescription;
use RuntimeException;

/**
 * Development stand-in for an e-prescribing service. Submissions stay
 * `pending` until a decision is simulated via the dev endpoint. Everything
 * it issues is flagged simulated. Refuses to run in production.
 */
class MockPrescriptionGateway implements PrescriptionGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function submit(Prescription $prescription): GatewayDecision
    {
        $this->guard();

        return new GatewayDecision(GatewayDecision::PENDING, 'MOCK-RX-'.strtoupper(substr(hash('sha256', $prescription->id), 0, 12)));
    }

    public function cancel(Prescription $prescription, string $reason): void
    {
        $this->guard();
    }

    public function status(Prescription $prescription): GatewayDecision
    {
        $this->guard();

        $status = match ($prescription->status) {
            PrescriptionStatus::Issued, PrescriptionStatus::Dispensed => GatewayDecision::ISSUED,
            PrescriptionStatus::Rejected => GatewayDecision::REJECTED,
            PrescriptionStatus::Cancelled, PrescriptionStatus::Expired => GatewayDecision::CANCELLED,
            default => GatewayDecision::PENDING,
        };

        return new GatewayDecision($status, (string) $prescription->external_reference);
    }

    private function guard(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock prescription gateway must not be used in production.');
        }
    }
}
