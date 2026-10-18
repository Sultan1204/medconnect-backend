<?php

namespace App\Modules\Prescriptions\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\MedicalRecords\Models\PatientMedication;
use App\Modules\MedicalRecords\Services\CareAccess;
use App\Modules\Prescriptions\Contracts\ClinicalSafetyChecker;
use App\Modules\Prescriptions\Contracts\PrescriptionGateway;
use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Modules\Prescriptions\Data\SafetyCheckResult;
use App\Modules\Prescriptions\Enums\PrescriptionStatus as Status;
use App\Modules\Prescriptions\Events\PrescriptionStatusChanged;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Models\PrescriptionEvent;
use App\Modules\Prescriptions\Models\PrescriptionItem;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 *   draft ──submit──► pending_validation ──(passed / warnings acknowledged)──► pending_external_approval
 *     ▲                     │ (blocked or edited)                                   │
 *     └─────────────────────┘                                       issued ◄────────┤ authorised service
 *                                                                     │             └──► rejected
 *                                                          dispensed ◄┴► expired / cancelled
 *
 * Issuance only ever comes from the PrescriptionGateway. A doctor action can
 * never mark a prescription issued.
 */
class PrescriptionWorkflow
{
    public function __construct(
        private readonly PrescriptionGateway $gateway,
        private readonly ClinicalSafetyChecker $safety,
        private readonly CareAccess $care,
        private readonly IntegrationLogger $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function createDraft(Consultation $consultation, User $doctorUser, array $items, ?string $indication): Prescription
    {
        $this->assertPrescriber($consultation, $doctorUser);

        // Prescribing requires an actual consultation, not just pre-visit record access.
        if (! in_array($consultation->status, [ConsultationStatus::Active, ConsultationStatus::Interrupted, ConsultationStatus::Completed], true)) {
            throw ApiException::conflict('consultation_not_started', 'Prescriptions can be written once the consultation has started.');
        }

        return DB::transaction(function () use ($consultation, $doctorUser, $items, $indication) {
            $prescription = Prescription::create([
                'consultation_id' => $consultation->id,
                'patient_id' => $consultation->patient_id,
                'prescriber_doctor_id' => $consultation->doctor_id,
                'prescriber_user_id' => $doctorUser->id,
                'clinical_indication' => $indication,
            ]);

            $this->replaceItems($prescription, $items);
            $this->event($prescription, 'created', null, Status::Draft, $doctorUser, ['items' => count($items)]);

            return $prescription->load('items');
        });
    }

    /**
     * Editing after validation returns the prescription to draft and clears check results.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    public function updateDraft(Prescription $prescription, User $doctorUser, ?array $items, ?string $indication, bool $indicationProvided): Prescription
    {
        $this->assertPrescriber($prescription->consultation, $doctorUser);

        if (! $prescription->status->isEditable()) {
            throw ApiException::conflict('prescription_locked', 'This prescription can no longer be edited.');
        }

        return DB::transaction(function () use ($prescription, $doctorUser, $items, $indication, $indicationProvided) {
            $from = $prescription->status;

            if ($items !== null) {
                $this->replaceItems($prescription, $items);
            }
            if ($indicationProvided) {
                $prescription->clinical_indication = $indication;
            }

            $prescription->forceFill([
                'status' => Status::Draft,
                'safety_check_status' => 'not_run',
                'safety_check_result' => null,
                'warnings_justification' => null,
                'warnings_acknowledged_at' => null,
            ])->save();

            $this->event($prescription, 'edited', $from, Status::Draft, $doctorUser);

            return $prescription->load('items');
        });
    }

    /**
     * Runs safety and controlled-medicine checks; forwards to the gateway when clear.
     */
    public function submit(Prescription $prescription, User $doctorUser): Prescription
    {
        $this->assertPrescriber($prescription->consultation, $doctorUser);

        if ($prescription->status !== Status::Draft) {
            throw ApiException::conflict('invalid_state_transition', "The prescription is {$prescription->status->value}.");
        }

        if ($prescription->items()->count() === 0) {
            throw ApiException::make('prescription_empty', 'Add at least one medication.');
        }

        $result = $this->runChecks($prescription->load('items'));

        $prescription = DB::transaction(function () use ($prescription, $doctorUser, $result) {
            $prescription->forceFill([
                'status' => Status::PendingValidation,
                'safety_check_status' => $result->status(),
                'safety_check_result' => $result->findings(),
            ])->save();

            $this->event($prescription, 'safety_check_'.$result->status(), Status::Draft, Status::PendingValidation, $doctorUser, [
                'checker' => $this->safety->name(),
                'finding_types' => array_values(array_unique(array_column($result->findings(), 'type'))),
            ]);

            return $prescription;
        });

        return $result->status() === 'passed' ? $this->sendToGateway($prescription, $doctorUser) : $prescription;
    }

    /**
     * The prescriber reviewed the warnings and confirms with a clinical justification.
     */
    public function acknowledgeWarnings(Prescription $prescription, User $doctorUser, string $justification): Prescription
    {
        $this->assertPrescriber($prescription->consultation, $doctorUser);

        if ($prescription->status !== Status::PendingValidation || $prescription->safety_check_status !== 'warnings') {
            throw ApiException::conflict('no_warnings_to_acknowledge', 'There are no outstanding warnings for this prescription.');
        }

        $prescription->forceFill(['warnings_justification' => $justification, 'warnings_acknowledged_at' => now()])->save();
        $this->event($prescription, 'warnings_acknowledged', Status::PendingValidation, Status::PendingValidation, $doctorUser);

        return $this->sendToGateway($prescription, $doctorUser);
    }

    /**
     * Applies a decision from the e-prescribing service (callback, poll or dev simulation).
     */
    public function applyGatewayDecision(Prescription $prescription, GatewayDecision $decision): Prescription
    {
        if ($prescription->status !== Status::PendingExternalApproval) {
            return $prescription;
        }

        return match ($decision->status) {
            GatewayDecision::ISSUED => $this->transition($prescription, Status::Issued, null, 'issued', [
                'issued_at' => now(),
                'expires_at' => $decision->expiresAt ?? CarbonImmutable::now()->addDays((int) config('medconnect.prescriptions.default_validity_days')),
                'is_simulated' => $this->gateway->isSimulated(),
            ]),
            GatewayDecision::REJECTED => $this->transition($prescription, Status::Rejected, null, 'rejected', ['status_reason' => $decision->reason]),
            default => $prescription,
        };
    }

    public function cancel(Prescription $prescription, User $actor, string $reason): Prescription
    {
        if (! $prescription->status->isCancellable()) {
            throw ApiException::conflict('prescription_not_cancellable', 'This prescription can no longer be cancelled.');
        }

        if ($prescription->external_reference !== null) {
            $this->integrations->call('prescriptions', 'cancel', $prescription, fn () => $this->gateway->cancel($prescription, $reason));
        }

        return $this->transition($prescription, Status::Cancelled, $actor, 'cancelled', ['cancelled_at' => now(), 'status_reason' => $reason]);
    }

    /** Called by the Pharmacies module when dispensing completes. */
    public function markDispensed(Prescription $prescription, User $pharmacist): Prescription
    {
        if ($prescription->status !== Status::Issued) {
            throw ApiException::conflict('prescription_not_dispensable', 'The prescription is not in an issued state.');
        }

        return $this->transition($prescription, Status::Dispensed, $pharmacist, 'dispensed');
    }

    public function expireIssued(): int
    {
        $count = 0;

        Prescription::where('status', Status::Issued)->where('expires_at', '<', now())->each(function (Prescription $p) use (&$count) {
            $this->transition($p, Status::Expired, null, 'expired');
            $count++;
        });

        return $count;
    }

    private function sendToGateway(Prescription $prescription, User $doctorUser): Prescription
    {
        $decision = $this->integrations->call('prescriptions', 'submit', $prescription,
            fn () => $this->gateway->submit($prescription->load('items')),
            fn (GatewayDecision $d) => $d->externalReference,
        );

        $prescription = $this->transition($prescription, Status::PendingExternalApproval, $doctorUser, 'submitted_to_gateway', [
            'gateway' => $this->gateway->name(),
            'external_reference' => $decision->externalReference,
            'submitted_at' => now(),
        ]);

        return $this->applyGatewayDecision($prescription, $decision);
    }

    private function runChecks(Prescription $prescription): SafetyCheckResult
    {
        $allergies = PatientAllergy::where('patient_id', $prescription->patient_id)->where('status', 'active')->get()->pluck('allergen')->all();
        $medications = PatientMedication::where('patient_id', $prescription->patient_id)->where('status', 'active')->get()->pluck('name')->all();

        $result = $this->safety->check($prescription, $allergies, $medications);

        $controlled = (array) config('medconnect.prescriptions.controlled_codes');
        foreach ($prescription->items as $index => $item) {
            $isControlled = in_array($item->catalog_code, $controlled, true);
            if ($isControlled !== $item->is_controlled) {
                $item->forceFill(['is_controlled' => $isControlled])->save();
            }

            if ($isControlled && ! config('medconnect.prescriptions.controlled_allowed_via_telemedicine')) {
                $result->add('block', 'controlled_medication', "{$item->medication_name} is a controlled medicine and cannot be prescribed via telemedicine.", $index);
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(Prescription $prescription, array $items): void
    {
        $prescription->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $prescription->items()->create(array_intersect_key($item, array_flip(PrescriptionItem::FIELDS)) + ['position' => $position]);
        }
    }

    private function assertPrescriber(Consultation $consultation, User $doctorUser): void
    {
        $this->care->authorize($doctorUser, $consultation);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Prescription $prescription, Status $to, ?User $actor, string $type, array $attributes = []): Prescription
    {
        $prescription = DB::transaction(function () use ($prescription, $to, $actor, $type, $attributes) {
            /** @var Prescription $locked */
            $locked = Prescription::whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $locked->forceFill(['status' => $to] + $attributes)->save();
            $this->event($locked, $type, $from, $to, $actor);

            DB::afterCommit(fn () => PrescriptionStatusChanged::dispatch($locked, $from));

            return $locked;
        });

        return $prescription->load('items');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(Prescription $prescription, string $type, ?Status $from, Status $to, ?User $actor, array $metadata = []): void
    {
        PrescriptionEvent::create([
            'prescription_id' => $prescription->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor?->id,
            'metadata' => $metadata ?: null,
        ]);

        $this->audit->record('prescriptions.'.$type, $prescription, [
            'actor' => $actor,
            'patient_id' => $prescription->patient_id,
            'purpose' => 'consultation:'.$prescription->consultation_id,
        ]);
    }
}
