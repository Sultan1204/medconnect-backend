<?php

namespace App\Modules\MedicalRecords\Http\Resources;

use App\Modules\MedicalRecords\Models\ClinicalNoteVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalNoteVersion
 */
class ClinicalNoteVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'content' => $this->content,
            'author' => ['user_id' => $this->author_user_id, 'name' => $this->whenLoaded('authorUser', fn () => $this->authorUser->name)],
            'change_reason' => $this->change_reason,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
