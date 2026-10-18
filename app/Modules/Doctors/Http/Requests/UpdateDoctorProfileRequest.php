<?php

namespace App\Modules\Doctors\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name_en' => ['sometimes', 'string', 'min:2', 'max:150'],
            'display_name_ar' => ['sometimes', 'nullable', 'string', 'min:2', 'max:150'],
            'title' => ['sometimes', 'nullable', 'string', 'max:30'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'bio_en' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'bio_ar' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'years_of_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:70'],
            // ISO 639-1 codes, e.g. ["ar", "en"].
            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['string', 'regex:/^[a-z]{2}$/', 'distinct'],
            'professional_rank' => ['sometimes', 'nullable', Rule::in(['consultant', 'senior_specialist', 'specialist', 'general_practitioner', 'resident'])],

            'license_number' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'licensing_authority' => ['sometimes', 'nullable', Rule::in(config('medconnect.doctors.licensing_authorities'))],
            'license_expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],

            // Halalas. null = not offered, 0 = free.
            'video_fee_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.config('medconnect.doctors.max_fee_amount')],
            'chat_fee_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.config('medconnect.doctors.max_fee_amount')],
            'accepting_patients' => ['sometimes', 'boolean'],

            'specialties' => ['sometimes', 'array', 'min:1', 'max:5', $this->exactlyOnePrimary()],
            'specialties.*.id' => ['required', 'uuid', 'distinct', Rule::exists('specialties', 'id')->where('is_active', true)],
            'specialties.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Pivot data for BelongsToMany::sync(), keyed by specialty id.
     *
     * @return array<string, array{is_primary: bool}>
     */
    public function specialtySync(): array
    {
        $sync = [];

        foreach ($this->validated('specialties', []) as $specialty) {
            $sync[(string) $specialty['id']] = ['is_primary' => (bool) ($specialty['is_primary'] ?? false)];
        }

        return $sync;
    }

    private function exactlyOnePrimary(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $primaries = collect(is_array($value) ? $value : [])->filter(fn ($s) => (bool) ($s['is_primary'] ?? false))->count();

            if ($primaries !== 1) {
                $fail('Exactly one specialty must be marked as primary.');
            }
        };
    }
}
