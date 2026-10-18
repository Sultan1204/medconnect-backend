<?php

namespace App\Modules\Pharmacies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Pharmacies\Enums\DispenseStatus;
use App\Modules\Pharmacies\Http\Resources\BranchResource;
use App\Modules\Pharmacies\Http\Resources\DispenseRequestResource;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Services\DispenseWorkflow;
use App\Modules\Pharmacies\Services\PharmacyAccess;
use App\Modules\Prescriptions\Contracts\PrescriptionGateway;
use App\Modules\Prescriptions\Data\GatewayDecision;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pharmacy staff portal. Staff see requests for their own branches only.
 *
 * @tags Pharmacies - Portal
 */
class PharmacyPortalController extends Controller
{
    public function __construct(
        private readonly PharmacyAccess $access,
        private readonly DispenseWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Incoming dispensing requests.
     *
     * Defaults to open requests (requested, received, under_review, ready).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = $this->access->requireStaff($this->user($request));
        $filters = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(DispenseStatus::class)],
            'branch_id' => ['sometimes', 'uuid'],
        ]);

        $statuses = $filters['status'] ?? array_map(fn (DispenseStatus $s) => $s->value, array_filter(DispenseStatus::cases(), fn (DispenseStatus $s) => $s->isOpen()));

        $requests = DispenseRequest::query()
            ->whereHas('branch', fn (Builder $q) => $q->where('pharmacy_id', $staff->pharmacy_id))
            ->when($staff->branch_id, fn (Builder $q, string $branch) => $q->where('branch_id', $branch))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, string $branch) => $q->where('branch_id', $branch))
            ->whereIn('status', $statuses)
            ->with(['branch.pharmacy', 'patient', 'prescription.items', 'prescription.prescriber'])
            ->oldest()
            ->paginate(25);

        return DispenseRequestResource::collection($requests);
    }

    /**
     * Request detail.
     */
    public function show(Request $request, DispenseRequest $dispenseRequest): DispenseRequestResource
    {
        $this->access->requireHandle($this->user($request), $dispenseRequest);
        $this->audit->record('dispensing.viewed', $dispenseRequest, ['patient_id' => $dispenseRequest->patient_id, 'purpose' => 'dispensing']);

        return new DispenseRequestResource($dispenseRequest->load(['branch.pharmacy', 'patient', 'prescription.items', 'prescription.prescriber']));
    }

    /**
     * Update dispensing status.
     *
     * Allowed: requested → received/under_review; received → under_review;
     * under_review → ready; ready → dispensed; any open status → rejected
     * (with `reason`). Only pharmacists can mark `ready`/`dispensed`.
     */
    public function updateStatus(Request $request, DispenseRequest $dispenseRequest): DispenseRequestResource
    {
        $staff = $this->access->requireHandle($this->user($request), $dispenseRequest);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DispenseStatus::class)],
            'reason' => ['nullable', 'string', Rule::in(DispenseRequest::REJECTION_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->workflow->transition($dispenseRequest, $staff, DispenseStatus::from($data['status']), $data['reason'] ?? null, $data['note'] ?? null);

        return new DispenseRequestResource($updated->load(['branch.pharmacy', 'patient', 'prescription.items', 'prescription.prescriber']));
    }

    /**
     * Verify the prescription with the e-prescribing service before dispensing.
     */
    public function verify(Request $request, DispenseRequest $dispenseRequest, PrescriptionGateway $gateway, IntegrationLogger $integrations): JsonResponse
    {
        $this->access->requireHandle($this->user($request), $dispenseRequest);
        $prescription = $dispenseRequest->prescription;

        $decision = $integrations->call('prescriptions', 'verify', $prescription, fn () => $gateway->status($prescription), fn (GatewayDecision $d) => $d->externalReference);

        return response()->json(['data' => [
            'valid_for_dispensing' => $decision->status === GatewayDecision::ISSUED && $prescription->isDispensable(),
            'service_status' => $decision->status,
            'legal_validity' => $prescription->legalValidity(),
            'expires_at' => $prescription->expires_at?->toIso8601ZuluString(),
            'verified_at' => now()->toIso8601ZuluString(),
        ]]);
    }

    /**
     * My pharmacy's branches.
     */
    public function branches(Request $request): AnonymousResourceCollection
    {
        $staff = $this->access->requireStaff($this->user($request));

        return BranchResource::collection(
            PharmacyBranch::where('pharmacy_id', $staff->pharmacy_id)
                ->when($staff->branch_id, fn (Builder $q, string $id) => $q->whereKey($id))
                ->with('pharmacy')->get(),
        );
    }

    /**
     * Update branch operational details (managers).
     */
    public function updateBranch(Request $request, PharmacyBranch $branch): BranchResource
    {
        $staff = $this->access->requireStaff($this->user($request));

        if ($staff->role !== 'manager' || $branch->pharmacy_id !== $staff->pharmacy_id || ! $staff->coversBranch($branch->id)) {
            throw ApiException::forbidden();
        }

        $branch->update($request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'opening_hours' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'accepts_eprescriptions' => ['sometimes', 'boolean'],
        ]));
        $this->audit->record('pharmacies.branch_updated', $branch, ['changes' => AuditLogger::changedFields($branch)]);

        return new BranchResource($branch->load('pharmacy'));
    }

    /**
     * Operational summary for my pharmacy (last 30 days).
     */
    public function report(Request $request): JsonResponse
    {
        $staff = $this->access->requireStaff($this->user($request));

        $base = DispenseRequest::query()
            ->whereHas('branch', fn (Builder $q) => $q->where('pharmacy_id', $staff->pharmacy_id))
            ->when($staff->branch_id, fn (Builder $q, string $id) => $q->where('branch_id', $id))
            ->where('created_at', '>=', now()->subDays(30));

        $byStatus = (clone $base)->groupBy('status')->select('status', DB::raw('count(*) AS total'))->pluck('total', 'status');
        $avgReady = (clone $base)->whereNotNull('ready_at')->selectRaw('AVG(EXTRACT(EPOCH FROM (ready_at - created_at)) / 60) AS minutes')->value('minutes');

        return response()->json(['data' => [
            'period_days' => 30,
            'by_status' => $byStatus,
            'average_minutes_to_ready' => $avgReady !== null ? round((float) $avgReady, 1) : null,
            'rejections_by_reason' => (clone $base)->where('status', 'rejected')->groupBy('rejection_reason')
                ->select('rejection_reason', DB::raw('count(*) AS total'))->pluck('total', 'rejection_reason'),
        ]]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
