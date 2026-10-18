<?php

namespace App\Modules\Pharmacies\Http\Resources;

use App\Modules\Pharmacies\Models\PharmacyBranch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PharmacyBranch
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $this->id,
            'pharmacy' => $this->whenLoaded('pharmacy', fn () => [
                'id' => $this->pharmacy->id,
                'name' => $ar ? $this->pharmacy->name_ar : $this->pharmacy->name_en,
            ]),
            'name' => $ar ? $this->name_ar : $this->name_en,
            'city' => $this->city,
            'district' => $this->district,
            'address_line' => $this->address_line,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'distance_km' => $this->when(isset($this->resource->distance_km), fn () => round((float) $this->resource->getAttribute('distance_km'), 2)),
            'phone' => $this->phone,
            'opening_hours' => $this->opening_hours,
            'is_active' => $this->is_active,
            'accepts_eprescriptions' => $this->accepts_eprescriptions,
        ];
    }
}
