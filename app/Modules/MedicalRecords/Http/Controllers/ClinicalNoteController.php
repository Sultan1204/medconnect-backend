<?php

namespace App\Modules\MedicalRecords\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Http\Resources\ClinicalNoteResource;
use App\Modules\MedicalRecords\Http\Resources\ClinicalNoteVersionResource;
use App\Modules\MedicalRecords\Models\ClinicalNote;
use App\Modules\MedicalRecords\Services\CareAccess;
use App\Modules\MedicalRecords\Services\ClinicalNoteService;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Clinical documentation: subjective, objective, assessment, plan,
 * follow-up and referral sections.
 *
 * @tags Medical records - Clinical notes
 */
class ClinicalNoteController extends Controller
{
    public function __construct(
        private readonly ClinicalNoteService $notes,
        private readonly CareAccess $care,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The consultation's clinical note.
     *
     * The treating doctor sees drafts; the patient side sees finalised content only.
     */
    public function show(Request $request, Consultation $consultation): ClinicalNoteResource
    {
        /** @var User $user */
        $user = $request->user();
        $note = ClinicalNote::where('consultation_id', $consultation->id)->with(['latestVersion', 'author'])->firstOrFail();

        $isDoctor = $this->care->doctorMayAccess($user, $consultation);
        $isPatientSide = Gate::allows('viewClinical', $consultation->patient);

        if (! $isDoctor && ! ($isPatientSide && ! $note->isDraft())) {
            throw ApiException::forbidden();
        }

        $this->audit->record('clinical_notes.viewed', $note, [
            'patient_id' => $note->patient_id,
            'purpose' => $isDoctor ? 'consultation:'.$consultation->id : 'patient_self_service',
        ]);

        return new ClinicalNoteResource($note);
    }

    /**
     * Save the draft note (treating doctor).
     *
     * Replaces the draft sections sent. Fails once the note is final - use amend.
     */
    public function saveDraft(Request $request, Consultation $consultation): ClinicalNoteResource
    {
        $user = $this->doctor($request, $consultation);
        $note = $this->notes->saveDraft($consultation, $user, $request->validate($this->contentRules()));

        return new ClinicalNoteResource($note->load(['latestVersion', 'author']));
    }

    /**
     * Finalise the note (treating doctor).
     *
     * Requires `assessment` and `plan`. Creates immutable version 1.
     */
    public function finalize(Request $request, Consultation $consultation): ClinicalNoteResource
    {
        $user = $this->doctor($request, $consultation);
        $note = ClinicalNote::where('consultation_id', $consultation->id)->firstOrFail();

        return new ClinicalNoteResource($this->notes->finalize($note, $user)->load(['latestVersion', 'author']));
    }

    /**
     * Amend a final note (treating doctor).
     *
     * Adds a new version with the changed sections and a mandatory reason;
     * earlier versions remain unchanged and retrievable.
     */
    public function amend(Request $request, Consultation $consultation): ClinicalNoteResource
    {
        $user = $this->doctor($request, $consultation);
        $data = $request->validate($this->contentRules() + ['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $note = ClinicalNote::where('consultation_id', $consultation->id)->firstOrFail();

        $reason = $data['reason'];
        unset($data['reason']);

        return new ClinicalNoteResource($this->notes->amend($note, $user, $data, $reason)->load(['latestVersion', 'author']));
    }

    /**
     * Version history (treating doctor or patient side).
     */
    public function versions(Request $request, Consultation $consultation): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->care->doctorMayAccess($user, $consultation) && ! Gate::allows('viewClinical', $consultation->patient)) {
            throw ApiException::forbidden();
        }

        $note = ClinicalNote::where('consultation_id', $consultation->id)->firstOrFail();

        return ClinicalNoteVersionResource::collection($note->versions()->with('authorUser')->get());
    }

    private function doctor(Request $request, Consultation $consultation): User
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('conduct', $consultation);
        $this->care->authorize($user, $consultation);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function contentRules(): array
    {
        return collect(ClinicalNote::SECTIONS)
            ->mapWithKeys(fn (string $section) => [$section => ['sometimes', 'nullable', 'string', 'max:10000']])
            ->all();
    }
}
