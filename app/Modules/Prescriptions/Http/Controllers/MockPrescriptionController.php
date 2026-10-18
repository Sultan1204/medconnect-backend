<?php

namespace App\Modules\Prescriptions\Http\Controllers;

use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Modules\Prescriptions\Http\Resources\PrescriptionResource;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Services\PrescriptionWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Development only: simulates the e-prescribing service's decision.
 * Registered only when the mock gateway is active outside production.
 *
 * @tags Development
 */
class MockPrescriptionController
{
    /**
     * Simulate an e-prescribing decision.
     *
     * Resulting prescriptions are flagged `simulated_not_valid`.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, Prescription $prescription, PrescriptionWorkflow $workflow): PrescriptionResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['issue', 'reject'])],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $decision = new GatewayDecision(
            $data['decision'] === 'issue' ? GatewayDecision::ISSUED : GatewayDecision::REJECTED,
            (string) $prescription->external_reference,
            $data['reason'] ?? null,
        );

        return new PrescriptionResource($workflow->applyGatewayDecision($prescription, $decision));
    }
}
