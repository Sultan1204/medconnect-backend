<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\ContactMessage;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handling of contact-form messages.
 *
 * @tags Admin - Contact messages
 */
class ContactMessageAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Messages, oldest unhandled first. `handled=1` lists handled ones. Reading is audited.
     */
    public function index(Request $request): JsonResponse
    {
        $handled = $request->boolean('handled');

        $page = ContactMessage::query()
            ->when($handled, fn ($q) => $q->whereNotNull('handled_at'), fn ($q) => $q->whereNull('handled_at'))
            ->orderBy($handled ? 'handled_at' : 'created_at', $handled ? 'desc' : 'asc')
            ->paginate(50);

        $this->audit->record('contact_messages.listed', null, ['metadata' => ['handled' => $handled]]);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ContactMessage $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'subject' => $m->subject,
                'message' => $m->message,
                'created_at' => $m->created_at->toIso8601ZuluString(),
                'handled_at' => $m->handled_at?->toIso8601ZuluString(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Mark a message as handled.
     */
    public function handle(Request $request, ContactMessage $message): JsonResponse
    {
        if ($message->handled_at === null) {
            $message->forceFill(['handled_at' => now(), 'handled_by_user_id' => $request->user()->id])->save();
            $this->audit->record('contact_messages.handled', $message);
        }

        return response()->json(['data' => ['id' => $message->id, 'handled_at' => $message->handled_at?->toIso8601ZuluString()]]);
    }
}
