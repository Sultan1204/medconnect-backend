<?php

namespace App\Modules\Reviews\Http\Resources;

use App\Modules\Reviews\Models\ReviewReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewReport
 */
class ReviewReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'reason' => $this->reason,
            'details' => $this->details,
            'status' => $this->status,
            'handling_note' => $this->handling_note,
            'handled_at' => $this->handled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'review' => new ReviewResource($this->whenLoaded('review')),
        ];
    }
}
