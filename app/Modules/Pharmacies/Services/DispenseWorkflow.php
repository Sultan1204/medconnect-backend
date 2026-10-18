<?php

namespace App\Modules\Pharmacies\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Pharmacies\Contracts\PharmacyGateway;
use App\Modules\Pharmacies\Enums\DispenseStatus as Status;
use App\Modules\Pharmacies\Models\DispenseEvent;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Services\PrescriptionWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DispenseWorkflow
{
    public function __construct(
        private readonly PharmacyGateway $gateway,
        private readonly PrescriptionWorkflow $prescriptions,
        private readonly ConsentService $consents,
        private readonly IntegrationLogger $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The patient (or guardian) sends an issued prescription to a chosen branch.
     */
    public function request(Prescription $prescription, User $actor, PharmacyBranch $branch): DispenseRequest
    {
        if (Gate::forUser($actor)->denies('actFor', $prescription->patient)) {
            throw ApiException::forbidden();
        }

        if (! $prescription->isDispensable()) {
            throw ApiException::conflict('prescription_not_dispensable', 'This prescription cannot be dispensed.');
        }

        if (! $branch->isEligible()) {
            throw ApiException::make('pharmacy_unavailable', 'This pharmacy branch cannot receive e-prescriptions.');
        }

        $latest = $this->consents->latest($prescription->patient)->get('pharmacy_data_sharing');
        if ($latest === null || ! $latest->granted || $latest->version !== ConsentService::currentVersion('pharmacy_data_sharing')) {
            throw ApiException::make('consent_required', 'Consent to share the prescription with a pharmacy is required.', 422, ['missing' => ['pharmacy_data_sharing']]);
        }

        try {
            $request = DB::transaction(function () use ($prescription, $actor, $branch) {
                $request = DispenseRequest::create([
                    'prescription_id' => $prescription->id,
                    'patient_id' => $prescription->patient_id,
                    'branch_id' => $branch->id,
                    'requested_by_user_id' => $actor->id,
                    'status' => Status::Requested,
                ]);
                $this->event($request, null, Status::Requested, $actor);

                return $request;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ApiException::conflict('dispense_request_exists', 'This prescription is already with a pharmacy. Cancel that request to choose another.');
            }

            throw $e;
        }

        $this->integrations->call('pharmacy', 'notify_requested', $request, fn () => $this->gateway->notify($request, 'requested'));

        return $request;
    }

    public function transition(DispenseRequest $request, PharmacyStaff $staff, Status $to, ?string $reason, ?string $note): DispenseRequest
    {
        return DB::transaction(function () use ($request, $staff, $to, $reason, $note) {
            /** @var DispenseRequest $locked */
            $locked = DispenseRequest::whereKey($request->id)->lockForUpdate()->with('prescription')->firstOrFail();

            if (! in_array($to, $locked->status->staffTransitions(), true)) {
                throw ApiException::conflict('invalid_state_transition', "Cannot move a {$locked->status->value} request to {$to->value}.");
            }

            if (in_array($to, [Status::Ready, Status::Dispensed], true) && ! $staff->canDispense()) {
                throw ApiException::forbidden('Only a pharmacist can prepare or dispense medication.', 'pharmacist_required');
            }

            // Re-verify validity before committing medication.
            if (in_array($to, [Status::Ready, Status::Dispensed], true) && ! $locked->prescription->isDispensable()) {
                throw ApiException::conflict('prescription_not_dispensable', 'The prescription is no longer valid for dispensing.');
            }

            if ($to === Status::Rejected && ! in_array($reason, DispenseRequest::REJECTION_REASONS, true)) {
                throw ApiException::make('rejection_reason_required', 'A rejection reason is required.', 422, ['allowed' => DispenseRequest::REJECTION_REASONS]);
            }

            $from = $locked->status;
            $locked->forceFill(array_filter([
                'status' => $to,
                'handled_by_user_id' => $staff->user_id,
                'rejection_reason' => $to === Status::Rejected ? $reason : null,
                'pharmacy_note' => $note,
                'ready_at' => $to === Status::Ready ? now() : null,
                'dispensed_at' => $to === Status::Dispensed ? now() : null,
            ], fn ($v) => $v !== null))->save();

            $this->event($locked, $from, $to, $staff->user, $reason);

            if ($to === Status::Dispensed) {
                $this->prescriptions->markDispensed($locked->prescription, $staff->user);
            }

            return $locked;
        });
    }

    public function cancel(DispenseRequest $request, User $actor, ?string $reason = null): DispenseRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            /** @var DispenseRequest $locked */
            $locked = DispenseRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPatientCancellable()) {
                throw ApiException::conflict('invalid_state_transition', "The request is {$locked->status->value}.");
            }

            $from = $locked->status;
            $locked->forceFill(['status' => Status::Cancelled])->save();
            $this->event($locked, $from, Status::Cancelled, $actor, $reason);

            return $locked;
        });
    }

    private function event(DispenseRequest $request, ?Status $from, Status $to, ?User $actor, ?string $reason = null): void
    {
        DispenseEvent::create([
            'dispense_request_id' => $request->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor?->id,
            'reason' => $reason,
        ]);

        $this->audit->record('dispensing.'.$to->value, $request, [
            'actor' => $actor,
            'patient_id' => $request->patient_id,
            'purpose' => 'dispensing',
        ]);
    }
}
