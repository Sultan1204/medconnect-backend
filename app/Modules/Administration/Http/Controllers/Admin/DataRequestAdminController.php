<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Patients\Models\PatientDataRequest;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Handling of data-subject requests. The system never deletes or changes
 * clinical data automatically: completing a request records the decision and
 * the work is carried out (and retention rules applied) by the data protection
 * team outside the API.
 *
 * @tags Admin - Data requests
 */
class DataRequestAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /**
     * Data requests, oldest first. Defaults to open requests.
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'completed', 'rejected'])],
            'type' => ['sometimes', Rule::in(PatientDataRequest::TYPES)],
        ]);

        $page = PatientDataRequest::query()
            ->where('status', $f['status'] ?? 'open')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderBy('created_at')->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->present($r, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * One request including the requester's details. Audited.
     */
    public function show(PatientDataRequest $dataRequest): JsonResponse
    {
        $this->audit->record('data_requests.viewed', $dataRequest, ['patient_id' => $dataRequest->patient_id, 'purpose' => 'data_subject_request']);

        return response()->json(['data' => $this->present($dataRequest, true)]);
    }

    /**
     * Progress or close a request. Closing needs a resolution. Requires step-up MFA.
     */
    public function handle(Request $request, PatientDataRequest $dataRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'completed', 'rejected'])],
            'resolution' => ['required_if:status,completed,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        $updated = DB::transaction(function () use ($dataRequest, $data, $request) {
            /** @var PatientDataRequest $locked */
            $locked = PatientDataRequest::whereKey($dataRequest->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['completed', 'rejected'], true)) {
                throw ApiException::conflict('data_request_closed', 'This request has already been closed.');
            }

            $from = $locked->status;
            $locked->status = $data['status'];

            if ($data['status'] !== 'in_progress') {
                $locked->resolution = $data['resolution'];
                $locked->resolved_by = $request->user()->id;
                $locked->resolved_at = now();
            }

            $locked->save();
            $this->audit->record('data_requests.handled', $locked, [
                'patient_id' => $locked->patient_id, 'purpose' => 'data_subject_request',
                'changes' => ['status' => [$from, $locked->status], 'type' => $locked->type],
            ]);

            return $locked;
        });

        $requester = User::find($updated->requested_by_user_id);

        if ($requester !== null) {
            $this->notifications->notify($requester, NotificationType::DataRequestUpdated, [], "data_request:{$updated->id}:{$updated->status}");
        }

        return response()->json(['data' => $this->present($updated, true)]);
    }

    /** @return array<string, mixed> */
    private function present(PatientDataRequest $r, bool $full): array
    {
        return [
            'id' => $r->id,
            'patient_id' => $r->patient_id,
            'type' => $r->type,
            'status' => $r->status,
            'created_at' => $r->created_at->toIso8601ZuluString(),
            'resolved_at' => $r->resolved_at?->toIso8601ZuluString(),
        ] + ($full ? ['details' => $r->details, 'resolution' => $r->resolution, 'requested_by_user_id' => $r->requested_by_user_id] : []);
    }
}
