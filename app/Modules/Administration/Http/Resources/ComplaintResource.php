<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Administration\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Complainant view by default; handler fields appear only with the permission.
 *
 * @mixin Complaint
 */
class ComplaintResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $handler = (bool) $request->user()?->can('complaints.manage');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'category' => $this->category,
            'description' => $this->description,
            'status' => $this->status->value,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'priority' => $this->when($handler, $this->priority),
            'assigned_to_user_id' => $this->when($handler, $this->assigned_to_user_id),
            'complainant_user_id' => $this->when($handler, $this->complainant_user_id),
            'events' => $this->whenLoaded('events', fn () => $this->events
                ->filter(fn ($e) => $handler || $e->visibility === 'public')
                ->values()
                ->map(fn ($e) => [
                    'type' => $e->type,
                    'from_status' => $e->from_status,
                    'to_status' => $e->to_status,
                    'note' => $handler ? $e->note : null,
                    'visibility' => $handler ? $e->visibility : null,
                    'created_at' => $e->created_at->toIso8601ZuluString(),
                ])),
        ];
    }
}
