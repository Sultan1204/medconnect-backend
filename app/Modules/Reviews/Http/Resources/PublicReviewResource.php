<?php

namespace App\Modules\Reviews\Http\Resources;

use App\Modules\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public (and the reviewed doctor) sees: the rating and text only.
 * No author, patient, consultation or appointment details.
 *
 * @mixin Review
 */
class PublicReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }
}
