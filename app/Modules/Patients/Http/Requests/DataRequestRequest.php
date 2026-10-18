<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\PatientDataRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DataRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(PatientDataRequest::TYPES)],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
