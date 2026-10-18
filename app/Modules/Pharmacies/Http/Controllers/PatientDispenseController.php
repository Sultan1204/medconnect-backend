<?php

namespace App\Modules\Pharmacies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pharmacies\Http\Resources\BranchResource;
use App\Modules\Pharmacies\Http\Resources\DispenseRequestResource;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Services\DispenseWorkflow;
use App\Modules\Prescriptions\Models\Prescription;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Pharmacies - Patient
 */
class PatientDispenseController extends Controller
{
    public function __construct(private readonly DispenseWorkflow $workflow) {}

    /**
     * Eligible pharmacy branches.
     *
     * Only active branches of approved, licensed pharmacies that accept
     * e-prescriptions. Pass `lat`/`lng` to sort by distance (km).
     */
    public function branches(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'city' => ['sometimes', 'string', 'max:80'],
            'lat' => ['required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'numeric', 'min:1', 'max:200'],
        ]);

        $query = PharmacyBranch::query()->eligible()->with('pharmacy');

        if (isset($filters['city'])) {
            $query->where('city', 'ilike', addcslashes($filters['city'], '%_\\'));
        }

        if (isset($filters['lat'], $filters['lng'])) {
            // Haversine distance in km.
            $distance = '6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';
            $bindings = [(float) $filters['lat'], (float) $filters['lng'], (float) $filters['lat']];

            $query->whereNotNull('latitude')
                ->select('pharmacy_branches.*')
                ->selectRaw("{$distance} AS distance_km", $bindings)
                ->whereRaw("{$distance} <= ?", [...$bindings, (float) ($filters['radius_km'] ?? 25)])
                ->orderBy('distance_km');
        } else {
            $query->orderBy('city')->orderBy('name_en');
        }

        return BranchResource::collection($query->paginate(20)->withQueryString());
    }

    /**
     * Send an issued prescription to a pharmacy.
     *
     * Requires current `pharmacy_data_sharing` consent. One pharmacy at a
     * time: cancel an open request before choosing another.
     */
    public function store(Request $request, Prescription $prescription): DispenseRequestResource
    {
        $branchId = $request->validate(['branch_id' => ['required', 'uuid']])['branch_id'];
        $branch = PharmacyBranch::with('pharmacy')->findOrFail($branchId);

        $dispense = $this->workflow->request($prescription, $request->user(), $branch);

        return new DispenseRequestResource($dispense->load(['branch.pharmacy', 'prescription.items']));
    }

    /**
     * Dispensing requests for a prescription.
     */
    public function index(Prescription $prescription): AnonymousResourceCollection
    {
        $this->authorize('viewClinical', $prescription->patient);

        return DispenseRequestResource::collection(
            DispenseRequest::where('prescription_id', $prescription->id)->with('branch.pharmacy')->latest()->get(),
        );
    }

    /**
     * Dispensing request status.
     */
    public function show(DispenseRequest $dispenseRequest): DispenseRequestResource
    {
        $this->authorize('viewClinical', $dispenseRequest->patient);

        return new DispenseRequestResource($dispenseRequest->load(['branch.pharmacy', 'prescription.items']));
    }

    /**
     * Cancel a dispensing request (patient side).
     */
    public function cancel(Request $request, DispenseRequest $dispenseRequest): DispenseRequestResource
    {
        if (Gate::denies('actFor', $dispenseRequest->patient)) {
            throw ApiException::forbidden();
        }

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;

        return new DispenseRequestResource($this->workflow->cancel($dispenseRequest, $request->user(), $reason)->load(['branch.pharmacy']));
    }
}
