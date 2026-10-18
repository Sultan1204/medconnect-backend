<?php

namespace App\Modules\Pharmacies\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Pharmacies\Http\Resources\BranchResource;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Pharmacies\Services\PharmacyOnboarding;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @tags Admin - Pharmacies
 */
class PharmacyAdminController extends Controller
{
    public function __construct(
        private readonly PharmacyOnboarding $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * List pharmacies.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'suspended'])]])['status'] ?? null;

        $pharmacies = Pharmacy::query()->withCount('branches')->when($status, fn ($q, $s) => $q->where('verification_status', $s))->latest()->paginate(25);

        return response()->json([
            'data' => collect($pharmacies->items())->map(fn (Pharmacy $p) => $this->present($p)),
            'meta' => ['total' => $pharmacies->total(), 'current_page' => $pharmacies->currentPage(), 'last_page' => $pharmacies->lastPage()],
        ]);
    }

    /**
     * Register a pharmacy (pending verification).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:60', Rule::unique('pharmacies')->where('licensing_authority', $request->input('licensing_authority'))],
            'licensing_authority' => ['required', 'string', 'max:30'],
            'license_expires_on' => ['required', 'date_format:Y-m-d', 'after:today'],
            'commercial_registration' => ['nullable', 'string', 'max:30'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:191'],
        ]);

        $pharmacy = Pharmacy::create($data);
        $this->audit->record('pharmacies.created', $pharmacy);

        return response()->json(['data' => $this->present($pharmacy)], 201);
    }

    /**
     * Pharmacy detail with branches and staff.
     */
    public function show(Pharmacy $pharmacy): JsonResponse
    {
        $pharmacy->load(['branches', 'staff.user']);

        return response()->json(['data' => $this->present($pharmacy) + [
            'branches' => BranchResource::collection($pharmacy->branches),
            'staff' => $pharmacy->staff->map(fn (PharmacyStaff $s) => [
                'id' => $s->id, 'user_id' => $s->user_id, 'name' => $s->user->name,
                'role' => $s->role, 'branch_id' => $s->branch_id, 'is_active' => $s->is_active,
            ]),
        ]]);
    }

    /**
     * Approve, reject or suspend a pharmacy (requires step-up MFA).
     *
     * Approve only after verifying the licence with the licensing authority.
     */
    public function verify(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'suspend'])],
            'reason' => ['required_unless:decision,approve', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->present($this->onboarding->decide($pharmacy, $data['decision'], $request->user(), $data['reason'] ?? null))]);
    }

    /**
     * Add a branch.
     */
    public function storeBranch(Request $request, Pharmacy $pharmacy): BranchResource
    {
        $branch = $pharmacy->branches()->create($request->validate([
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'address_line' => ['required', 'string', 'max:300'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'opening_hours' => ['nullable', 'array'],
        ]));

        $this->audit->record('pharmacies.branch_created', $branch);

        return new BranchResource($branch->load('pharmacy'));
    }

    /**
     * Add a staff member.
     *
     * Creates (or links) a pharmacy-staff account and sends a password
     * set-up code by SMS. MFA enrolment is required at first sign-in.
     */
    public function storeStaff(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['nullable', 'email', 'max:191'],
            'role' => ['required', Rule::in(['manager', 'pharmacist', 'assistant'])],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $staff = $this->onboarding->addStaff($pharmacy, $data, $request->user());

        return response()->json(['data' => ['id' => $staff->id, 'user_id' => $staff->user_id, 'role' => $staff->role, 'branch_id' => $staff->branch_id]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Pharmacy $pharmacy): array
    {
        return [
            'id' => $pharmacy->id,
            'name_en' => $pharmacy->name_en,
            'name_ar' => $pharmacy->name_ar,
            'license_number' => $pharmacy->license_number,
            'licensing_authority' => $pharmacy->licensing_authority,
            'license_expires_on' => $pharmacy->license_expires_on->toDateString(),
            'verification_status' => $pharmacy->verification_status,
            'status_reason' => $pharmacy->status_reason,
            'verified_at' => $pharmacy->verified_at?->toIso8601ZuluString(),
            'branches_count' => $pharmacy->getAttribute('branches_count'),
            'created_at' => $pharmacy->created_at->toIso8601ZuluString(),
        ];
    }
}
