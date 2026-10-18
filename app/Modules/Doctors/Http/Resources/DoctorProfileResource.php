<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorVerificationService;
use App\Modules\Specialties\Http\Resources\SpecialtyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full doctor profile for the doctor themselves and for verifying administrators.
 *
 * @mixin Doctor
 */
class DoctorProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = $request->user()?->id === $this->user_id;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'display_name_en' => $this->display_name_en,
            'display_name_ar' => $this->display_name_ar,
            'title' => $this->title,
            'gender' => $this->gender,
            'bio_en' => $this->bio_en,
            'bio_ar' => $this->bio_ar,
            'years_of_experience' => $this->years_of_experience,
            'languages' => $this->languages,
            'professional_rank' => $this->professional_rank,
            'license' => [
                'number' => $this->license_number,
                'authority' => $this->licensing_authority,
                'expires_on' => $this->license_expires_on?->toDateString(),
                'is_valid' => $this->isLicenseValid(),
            ],
            'verification' => [
                'status' => $this->verification_status->value,
                'changed_at' => $this->verification_status_changed_at?->toIso8601ZuluString(),
                'approved_at' => $this->approved_at?->toIso8601ZuluString(),
                'missing_requirements' => $this->when($isOwner && $this->verification_status->canSubmit(),
                    fn () => app(DoctorVerificationService::class)->missingRequirements($this->resource)),
            ],
            'fees' => [
                'video' => $this->video_fee_amount,
                'chat' => $this->chat_fee_amount,
                'currency' => $this->currency,
            ],
            'accepting_patients' => $this->accepting_patients,
            'is_publicly_listed' => $this->isPubliclyListed(),
            'specialties' => SpecialtyResource::collection($this->whenLoaded('specialties')),
            'contact' => $this->when(! $isOwner && $this->relationLoaded('user'), fn () => [
                'phone' => $this->user->phone,
                'email' => $this->user->email,
            ]),
            'credentials' => CredentialResource::collection($this->whenLoaded('credentials')),
            'verification_history' => VerificationEventResource::collection($this->whenLoaded('verificationHistory')),
            'rating' => ['average' => $this->rating_average !== null ? (float) $this->rating_average : null, 'count' => $this->rating_count],
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
