<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\AvailabilityRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailabilityRule
 */
class AvailabilityRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day_of_week' => $this->day_of_week,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'slot_minutes' => $this->slot_minutes,
            'breaks' => $this->breaks,
            'consultation_types' => $this->consultation_types,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
        ];
    }
}
