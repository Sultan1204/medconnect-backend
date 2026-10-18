<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only - the document is fetched via a short-lived download link.
 *
 * @mixin DoctorCredential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'issuer' => $this->issuer,
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'review_status' => $this->review_status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            'uploaded_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
