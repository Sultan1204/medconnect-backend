<?php

namespace App\Modules\Prescriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Services\CareAccess;
use App\Modules\Patients\Models\Patient;
use App\Modules\Prescriptions\Http\Requests\PrescriptionRequest;
use App\Modules\Prescriptions\Http\Resources\PrescriptionResource;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Services\PrescriptionWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Electronic prescription workflow. Drafts and pending prescriptions are not
 * valid prescriptions; issuance happens only at the authorised service.
 *
 * @tags Prescriptions
 */
class PrescriptionController extends Controller
{
    public function __construct(
        private readonly PrescriptionWorkflow $workflow,
        private readonly CareAccess $care,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a prescription draft (treating doctor).
     */
    public function store(PrescriptionRequest $request): PrescriptionResource
    {
        $consultation = Consultation::findOrFail($request->validated('consultation_id'));

        return new PrescriptionResource($this->workflow->createDraft(
            $consultation, $request->user(), $request->validated('items'), $request->validated('clinical_indication'),
        ));
    }

    /**
     * Prescription detail (prescriber or patient side).
     */
    public function show(Request $request, Prescription $prescription): PrescriptionResource
    {
        $this->authorizeView($request->user(), $prescription);
        $this->audit->record('prescriptions.viewed', $prescription, ['patient_id' => $prescription->patient_id]);

        return new PrescriptionResource($prescription->load('items'));
    }

    /**
     * Edit a draft (prescriber). Editing after validation returns it to draft.
     */
    public function update(PrescriptionRequest $request, Prescription $prescription): PrescriptionResource
    {
        $data = $request->validated();

        return new PrescriptionResource($this->workflow->updateDraft(
            $prescription, $request->user(), $data['items'] ?? null, $data['clinical_indication'] ?? null, array_key_exists('clinical_indication', $data),
        ));
    }

    /**
     * Validate and submit (prescriber).
     *
     * Runs allergy/interaction/duplication and controlled-medicine checks.
     * `blocked` findings require editing; `warnings` require
     * `POST /acknowledge-warnings`; otherwise the prescription is sent to the
     * e-prescribing service (`pending_external_approval`).
     */
    public function submit(Request $request, Prescription $prescription): PrescriptionResource
    {
        return new PrescriptionResource($this->workflow->submit($prescription, $request->user()));
    }

    /**
     * Acknowledge safety warnings with a clinical justification (prescriber).
     */
    public function acknowledgeWarnings(Request $request, Prescription $prescription): PrescriptionResource
    {
        $justification = $request->validate(['justification' => ['required', 'string', 'min:10', 'max:1000']])['justification'];

        return new PrescriptionResource($this->workflow->acknowledgeWarnings($prescription, $request->user(), $justification));
    }

    /**
     * Cancel (prescriber).
     */
    public function cancel(Request $request, Prescription $prescription): PrescriptionResource
    {
        /** @var User $user */
        $user = $request->user();
        $this->care->authorize($user, $prescription->consultation);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return new PrescriptionResource($this->workflow->cancel($prescription, $user, $reason));
    }

    /**
     * Prescriptions of a consultation.
     */
    public function forConsultation(Request $request, Consultation $consultation): AnonymousResourceCollection
    {
        $this->authorize('view', $consultation);

        return PrescriptionResource::collection(Prescription::where('consultation_id', $consultation->id)->with('items')->latest()->get());
    }

    /**
     * A patient's prescriptions (patient side). Drafts are not shown.
     */
    public function forPatient(Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('viewClinical', $patient);

        return PrescriptionResource::collection(
            Prescription::where('patient_id', $patient->id)->where('status', '!=', 'draft')->with('items')->latest()->paginate(20),
        );
    }

    private function authorizeView(User $user, Prescription $prescription): void
    {
        $isPrescriberSide = $this->care->doctorMayAccess($user, $prescription->consultation);
        $isPatientSide = Gate::forUser($user)->allows('viewClinical', $prescription->patient)
            && $prescription->status->value !== 'draft';

        if (! $isPrescriberSide && ! $isPatientSide) {
            throw ApiException::forbidden();
        }
    }
}
