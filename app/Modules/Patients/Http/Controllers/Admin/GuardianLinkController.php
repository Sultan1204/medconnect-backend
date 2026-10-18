<?php

namespace App\Modules\Patients\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Patients\Enums\GuardianDecision;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Http\Resources\GuardianLinkResource;
use App\Modules\Patients\Models\PatientGuardian;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * @tags Admin - Guardians
 */
class GuardianLinkController extends Controller
{
    /**
     * List guardian links.
     *
     * Defaults to links awaiting verification.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['sometimes', Rule::enum(GuardianStatus::class)]])['status'] ?? GuardianStatus::Pending->value;

        return GuardianLinkResource::collection(
            PatientGuardian::with(['guardian', 'patient'])->where('status', $status)->oldest()->paginate(20),
        );
    }

    /**
     * Approve, reject or revoke a guardian link.
     *
     * Approve only after verifying documentary evidence of the relationship
     * (e.g. family record or court order) through the support process.
     */
    public function decide(Request $request, PatientGuardian $link, AuditLogger $audit): GuardianLinkResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(GuardianDecision::class)],
            'reason' => ['required_unless:decision,approve', 'nullable', 'string', 'max:500'],
        ]);
        $decision = GuardianDecision::from($data['decision']);

        $allowed = match ($decision) {
            GuardianDecision::Approve, GuardianDecision::Reject => $link->status === GuardianStatus::Pending,
            GuardianDecision::Revoke => $link->status === GuardianStatus::Active,
        };

        if (! $allowed) {
            throw ApiException::conflict('invalid_state_transition', 'This guardian link cannot be changed in its current state.');
        }

        $link->forceFill(match ($decision) {
            GuardianDecision::Approve => ['status' => GuardianStatus::Active, 'verified_by' => $request->user()->id, 'verified_at' => now()],
            GuardianDecision::Reject => ['status' => GuardianStatus::Rejected, 'status_reason' => $data['reason']],
            GuardianDecision::Revoke => ['status' => GuardianStatus::Revoked, 'revoked_at' => now(), 'status_reason' => $data['reason']],
        })->save();

        $audit->record('patients.guardian_link_'.$decision->value, $link, ['patient_id' => $link->patient_id]);

        return new GuardianLinkResource($link->load(['guardian', 'patient']));
    }
}
