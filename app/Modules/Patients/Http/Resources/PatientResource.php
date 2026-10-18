<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Patient profile as seen by the patient or their guardian.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->age(),
            'gender' => $this->gender,
            'is_minor' => $this->isMinor(),
            'has_own_account' => $this->user_id !== null,
            'is_self' => $user !== null && $this->user_id === $user->id,
            'can_act' => $user?->can('actFor', $this->resource) ?? false,
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
                'relationship' => $this->emergency_contact_relationship,
            ],
            'guardians' => GuardianLinkResource::collection($this->whenLoaded('guardianLinks')),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
