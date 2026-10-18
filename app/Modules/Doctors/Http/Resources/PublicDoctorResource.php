<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Specialties\Http\Resources\SpecialtyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Doctor as shown to patients in search and on profile pages. Contains no
 * contact details, licence numbers or documents.
 *
 * @mixin Doctor
 */
class PublicDoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $this->id,
            'name' => $ar ? ($this->display_name_ar ?? $this->display_name_en) : $this->display_name_en,
            'name_en' => $this->display_name_en,
            'name_ar' => $this->display_name_ar,
            'title' => $this->title,
            'gender' => $this->gender,
            'bio' => $ar ? ($this->bio_ar ?? $this->bio_en) : ($this->bio_en ?? $this->bio_ar),
            'years_of_experience' => $this->years_of_experience,
            'languages' => $this->languages,
            'professional_rank' => $this->professional_rank,
            'specialties' => SpecialtyResource::collection($this->whenLoaded('specialties')),
            'consultation_types' => array_map(fn (string $type) => [
                'type' => $type,
                'fee' => ['amount' => $this->feeFor($type), 'currency' => $this->currency],
                'is_free' => $this->feeFor($type) === 0,
            ], $this->offeredConsultationTypes()),
            'rating' => [
                'average' => $this->rating_average !== null ? (float) $this->rating_average : null,
                'count' => $this->rating_count,
            ],
            'verification' => [
                'status' => 'verified',
                'licensing_authority' => $this->licensing_authority,
            ],
        ];
    }
}
