<?php

namespace App\Modules\Consultations\Http\Resources;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Consultation as seen by its participants.
 *
 * @mixin Consultation
 */
class ConsultationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'consultation_type' => $this->consultation_type,
            'your_role' => $request->user() ? $this->participantRole($request->user()) : null,
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
            'hard_ends_at' => $this->relationLoaded('appointment') ? $this->hardEndsAt()->toIso8601ZuluString() : null,
            'max_duration_minutes' => $this->max_duration_minutes,
            'intake' => [
                'submitted_at' => $this->intake_submitted_at?->toIso8601ZuluString(),
                'reason' => $this->intake_reason,
                'details' => $this->intake_details,
                'screening' => $this->intake_screening,
            ],
            'emergency_flagged' => $this->emergency_flagged,
            'channel' => 'private-consultation.'.$this->id,
        ];
    }
}
