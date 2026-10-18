<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Http\Requests\PatientProfileRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientGuardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Patient profiles for the signed-in patient and the dependants they manage.
 *
 * @tags Patients
 */
class PatientController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * My patient profile.
     */
    public function me(Request $request): PatientResource
    {
        return new PatientResource($this->ownProfile($request->user())->load('guardianLinks.guardian'));
    }

    /**
     * Update my patient profile.
     */
    public function updateMe(PatientProfileRequest $request): PatientResource
    {
        return $this->applyUpdate($request, $this->ownProfile($request->user()));
    }

    /**
     * Show a patient profile (self or dependant).
     */
    public function show(Request $request, Patient $patient): PatientResource
    {
        $this->authorize('view', $patient);

        if ($patient->user_id !== $request->user()->id) {
            $this->audit->record('patients.profile_viewed', $patient, ['patient_id' => $patient->id]);
        }

        return new PatientResource($patient->load('guardianLinks.guardian'));
    }

    /**
     * Update a patient profile (self or dependant).
     */
    public function update(PatientProfileRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        return $this->applyUpdate($request, $patient);
    }

    /**
     * Dependants I manage.
     *
     * Includes links awaiting administrator verification.
     */
    public function dependents(Request $request): AnonymousResourceCollection
    {
        $patients = Patient::query()
            ->whereHas('guardianLinks', fn ($q) => $q
                ->where('guardian_user_id', $request->user()->id)
                ->whereIn('status', [GuardianStatus::Active, GuardianStatus::Pending]))
            ->with(['guardianLinks' => fn ($q) => $q->where('guardian_user_id', $request->user()->id)])
            ->orderBy('full_name')
            ->get();

        return PatientResource::collection($patients);
    }

    /**
     * Add a dependant (e.g. a child).
     *
     * The guardian link is `pending` until verified by an administrator
     * (configurable); until then the guardian cannot act for the dependant.
     */
    public function storeDependent(PatientProfileRequest $request): PatientResource
    {
        /** @var User $user */
        $user = $request->user();

        $patient = DB::transaction(function () use ($request, $user) {
            $patient = Patient::create($request->profileAttributes() + ['created_by_user_id' => $user->id]);

            $link = PatientGuardian::create([
                'patient_id' => $patient->id,
                'guardian_user_id' => $user->id,
                'relationship' => $request->validated('relationship'),
                'status' => config('medconnect.patients.guardian_links_require_verification')
                    ? GuardianStatus::Pending : GuardianStatus::Active,
            ]);

            $this->audit->record('patients.dependent_created', $patient, [
                'patient_id' => $patient->id,
                'metadata' => ['guardian_link' => $link->id, 'link_status' => $link->status->value],
            ]);

            return $patient;
        });

        return new PatientResource($patient->load(['guardianLinks' => fn ($q) => $q->where('guardian_user_id', $user->id)]));
    }

    private function applyUpdate(PatientProfileRequest $request, Patient $patient): PatientResource
    {
        $patient->update($request->profileAttributes());
        $this->audit->record('patients.profile_updated', $patient, [
            'patient_id' => $patient->id,
            'changes' => AuditLogger::changedFields($patient),
        ]);

        return new PatientResource($patient->load('guardianLinks.guardian'));
    }

    private function ownProfile(User $user): Patient
    {
        return Patient::where('user_id', $user->id)->firstOrFail();
    }
}
