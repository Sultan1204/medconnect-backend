<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\PatientGuardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientGuardian
 */
class GuardianLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'guardian' => $this->whenLoaded('guardian', fn () => [
                'id' => $this->guardian->id,
                'name' => $this->guardian->name,
            ]),
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'date_of_birth' => $this->patient->date_of_birth?->toDateString(),
            ]),
            'relationship' => $this->relationship,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
