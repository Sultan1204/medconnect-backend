<?php

namespace App\Modules\Specialties\Http\Resources;

use App\Modules\Specialties\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Specialty
 */
class SpecialtyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            // Localized by Accept-Language; both languages are always included.
            'name' => $ar ? $this->name_ar : $this->name_en,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'description' => $ar ? $this->description_ar : $this->description_en,
            'is_active' => $this->is_active,
            'is_primary' => $this->whenPivotLoaded('doctor_specialties', fn () => (bool) $this->resource->getRelation('pivot')->getAttribute('is_primary')),
        ];
    }
}
