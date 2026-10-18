<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\AvailabilitySlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailabilitySlot
 */
class AvailabilitySlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'slot_minutes' => $this->slot_minutes,
            'consultation_types' => $this->consultation_types,
            'note' => $this->note,
        ];
    }
}
