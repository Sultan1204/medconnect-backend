<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit log review. Entries hold identifiers and field names, never clinical
 * content. Reviewing the log is itself audited.
 *
 * @tags Admin - Audit
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Search the audit log (newest first, cursor pagination).
     *
     * `action` matches exactly, or by prefix with a trailing `*` (e.g. `payments.*`).
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'actor_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_.]+\*?$/i'],
            'subject_type' => ['sometimes', 'string', 'max:60'],
            'subject_id' => ['sometimes', 'uuid'],
            'patient_id' => ['sometimes', 'uuid'],
            'outcome' => ['sometimes', 'string', 'max:20'],
            'request_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::query()
            ->when($f['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['action'] ?? null, function ($q, $v) {
                str_ends_with($v, '*')
                    ? $q->where('action', 'like', addcslashes(rtrim($v, '*'), '\\%_').'%')
                    : $q->where('action', $v);
            })
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))
            ->when($f['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($f['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')->orderByDesc('id');

        // Record the review itself (filter names only, never values).
        $this->audit->record('audit.viewed', null, ['metadata' => ['filters' => array_keys($f)]]);

        $page = $query->cursorPaginate($f['per_page'] ?? 50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'actor_id' => $l->actor_id,
                'actor_role' => $l->actor_role,
                'action' => $l->action,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'patient_id' => $l->patient_id,
                'purpose' => $l->purpose,
                'changes' => $l->changes,
                'metadata' => $l->metadata,
                'outcome' => $l->outcome,
                'request_id' => $l->request_id,
                'created_at' => $l->created_at->toIso8601ZuluString(),
            ])->values(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()],
        ]);
    }
}
