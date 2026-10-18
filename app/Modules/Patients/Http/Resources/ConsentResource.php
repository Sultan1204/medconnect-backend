<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Patients\Services\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientConsent
 */
class ConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->consent_type,
            'version' => $this->version,
            'granted' => $this->granted,
            'is_current_version' => $this->version === ConsentService::currentVersion($this->consent_type),
            'recorded_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
