<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorVerification
 */
class VerificationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdministrator() ?? false;

        return [
            'id' => $this->id,
            'action' => $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'reason' => $this->reason,
            'source' => $this->source,
            // Reviewer identity and evidence are internal.
            'actor_id' => $this->when($isAdmin, $this->actor_id),
            'evidence' => $this->when($isAdmin, $this->evidence),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
