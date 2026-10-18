<?php

namespace App\Modules\Reviews\Http\Resources;

use App\Modules\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The author's (and moderators') view, including moderation state.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'doctor_id' => $this->doctor_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'moderation_note' => $this->when($request->user()?->can('reviews.moderate'), $this->moderation_note),
            'moderated_at' => $this->when($request->user()?->can('reviews.moderate'), fn () => $this->moderated_at?->toIso8601ZuluString()),
            'reports_count' => $this->whenCounted('reports'),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
