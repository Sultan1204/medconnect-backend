<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\PatientDataRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientDataRequest
 */
class DataRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'type' => $this->type,
            'status' => $this->status,
            'details' => $this->details,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
