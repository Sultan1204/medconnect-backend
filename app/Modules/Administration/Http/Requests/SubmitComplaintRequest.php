<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Administration\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitComplaintRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(Complaint::SUBJECT_TYPES)],
            'subject_id' => [Rule::requiredIf(fn () => $this->input('subject_type') !== 'platform'), 'nullable', 'uuid'],
            'category' => ['required', Rule::in(Complaint::CATEGORIES)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
