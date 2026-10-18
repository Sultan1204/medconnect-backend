<?php

namespace App\Modules\MedicalRecords\Http\Resources;

use App\Modules\MedicalRecords\Models\ClinicalNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Draft content is shown only to the authoring doctor; everyone else sees
 * the latest finalised version.
 *
 * @mixin ClinicalNote
 */
class ClinicalNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAuthor = $this->relationLoaded('author') && $this->author->user_id === $request->user()?->id;

        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'status' => $this->status,
            'version' => $this->current_version,
            'content' => $this->isDraft()
                ? ($isAuthor ? $this->draft_content : null)
                : $this->whenLoaded('latestVersion', fn () => $this->latestVersion?->content),
            'author' => $this->whenLoaded('author', fn () => [
                'doctor_id' => $this->author->id,
                'name' => $this->author->display_name_en,
            ]),
            'finalized_at' => $this->finalized_at?->toIso8601ZuluString(),
            'last_changed_at' => $this->whenLoaded('latestVersion', fn () => $this->latestVersion?->created_at->toIso8601ZuluString()),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
