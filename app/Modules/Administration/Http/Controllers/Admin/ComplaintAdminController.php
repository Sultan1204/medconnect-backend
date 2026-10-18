<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Enums\ComplaintStatus;
use App\Modules\Administration\Http\Requests\HandleComplaintRequest;
use App\Modules\Administration\Http\Resources\ComplaintResource;
use App\Modules\Administration\Models\Complaint;
use App\Modules\Administration\Services\ComplaintService;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Complaint handling queue.
 *
 * @tags Admin - Complaints
 */
class ComplaintAdminController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints, private readonly AuditLogger $audit) {}

    /**
     * Complaint queue: highest priority and oldest first. Defaults to open complaints.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'status' => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'priority' => ['sometimes', Rule::in(Complaint::PRIORITIES)],
            'assigned_to_user_id' => ['sometimes', 'uuid'],
        ]);

        $query = Complaint::query()
            ->where('status', $f['status'] ?? 'open')
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($f['assigned_to_user_id'] ?? null, fn ($q, $v) => $q->where('assigned_to_user_id', $v))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('created_at');

        return ComplaintResource::collection($query->paginate(50));
    }

    /**
     * Complaint with full history. Viewing is audited because it may contain health information.
     */
    public function show(Request $request, Complaint $complaint): ComplaintResource
    {
        $this->authorize('manage', $complaint);
        $this->audit->record('complaints.viewed', $complaint, ['patient_id' => $complaint->patient_id, 'purpose' => 'complaint_handling']);

        return new ComplaintResource($complaint->load('events'));
    }

    /**
     * Assign, prioritise, add an internal note, or close with a resolution.
     */
    public function update(HandleComplaintRequest $request, Complaint $complaint): ComplaintResource
    {
        $this->authorize('manage', $complaint);

        return new ComplaintResource($this->complaints->handle($complaint, $request->user(), $request->validated())->load('events'));
    }
}
