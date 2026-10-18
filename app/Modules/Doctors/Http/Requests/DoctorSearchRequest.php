<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Name (Arabic or English), partial match.
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            // Specialty UUID or slug.
            'specialty' => ['sometimes', 'string', 'max:80'],
            'language' => ['sometimes', 'string', 'regex:/^[a-z]{2}$/'],
            'consultation_type' => ['sometimes', Rule::in(['video', 'chat'])],
            'gender' => ['sometimes', Rule::in(['male', 'female'])],
            // Halalas.
            'min_price' => ['sometimes', 'integer', 'min:0'],
            'max_price' => ['sometimes', 'integer', 'min:0', Rule::when($this->filled('min_price'), 'gte:min_price')],
            // Only doctors with a free slot on this date (Asia/Riyadh).
            'available_on' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'sort' => ['sometimes', Rule::in(['price', '-price', '-rating', '-experience', 'name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
