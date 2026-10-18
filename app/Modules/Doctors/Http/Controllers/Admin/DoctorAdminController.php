<?php

namespace App\Modules\Doctors\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Http\Controllers\CredentialController;
use App\Modules\Doctors\Http\Requests\VerificationDecisionRequest;
use App\Modules\Doctors\Http\Resources\DoctorProfileResource;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorVerificationService;
use App\Modules\Identity\Models\User;
use App\Support\Files\PrivateFileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Professional verification of doctors by administrators.
 *
 * Decisions (approve/reject/suspend/reinstate) require step-up MFA via the
 * `X-MFA-Code` header.
 *
 * @tags Admin - Doctors
 */
class DoctorAdminController extends Controller
{
    public function __construct(private readonly DoctorVerificationService $verification) {}

    /**
     * Doctors awaiting verification.
     */
    public function pending(): AnonymousResourceCollection
    {
        return $this->listing(VerificationStatus::UnderReview->value);
    }

    /**
     * List doctors by verification status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['sometimes', Rule::enum(VerificationStatus::class)]])['status'] ?? null;

        return $this->listing($status);
    }

    /**
     * Doctor detail with credentials and verification history.
     */
    public function show(Doctor $doctor): DoctorProfileResource
    {
        $this->authorize('viewPrivate', $doctor);

        return new DoctorProfileResource($doctor->load(['user', 'specialties', 'credentials', 'verificationHistory']));
    }

    /**
     * Approve or reject a doctor.
     *
     * Optionally review individual credentials in the same request. Approval
     * requires a valid licence and at least one accepted licence document.
     */
    public function verify(VerificationDecisionRequest $request, Doctor $doctor): DoctorProfileResource
    {
        $this->authorize('verify', $doctor);

        /** @var User $admin */
        $admin = $request->user();
        $reviews = $request->validated('credentials', []);

        $doctor = $request->validated('decision') === 'approve'
            ? $this->verification->approve($doctor, $admin, $request->validated('note'), $reviews)
            : $this->verification->reject($doctor, $admin, (string) $request->validated('reason'), $reviews);

        return new DoctorProfileResource($doctor->load(['user', 'specialties', 'credentials', 'verificationHistory']));
    }

    /**
     * Suspend a doctor.
     *
     * Immediately removes the doctor from search and blocks new consultations.
     */
    public function suspend(Request $request, Doctor $doctor): DoctorProfileResource
    {
        $this->authorize('verify', $doctor);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return new DoctorProfileResource($this->verification->suspend($doctor, $request->user(), $reason)->load('specialties'));
    }

    /**
     * Reinstate a suspended doctor.
     */
    public function reinstate(Request $request, Doctor $doctor): DoctorProfileResource
    {
        $this->authorize('verify', $doctor);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return new DoctorProfileResource($this->verification->reinstate($doctor, $request->user(), $reason)->load('specialties'));
    }

    /**
     * Short-lived download link for a doctor's credential document.
     */
    public function downloadCredential(Doctor $doctor, string $credential, PrivateFileStore $files): JsonResponse
    {
        $this->authorize('viewPrivate', $doctor);

        return CredentialController::downloadLink($files, $doctor->credentials()->findOrFail($credential));
    }

    private function listing(?string $status): AnonymousResourceCollection
    {
        $doctors = Doctor::query()
            ->with(['user', 'specialties'])
            ->when($status, fn ($q) => $q->where('verification_status', $status))
            ->orderBy('verification_status_changed_at')
            ->paginate(20);

        return DoctorProfileResource::collection($doctors);
    }
}
