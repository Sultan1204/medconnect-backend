<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(AppointmentStatus::class)],
            // `upcoming` (default) or `past`.
            'when' => ['sometimes', Rule::in(['upcoming', 'past', 'all'])],
            'patient_id' => ['sometimes', 'uuid'],
            'doctor_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
