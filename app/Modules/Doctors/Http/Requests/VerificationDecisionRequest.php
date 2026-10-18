<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerificationDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            // Required when rejecting; shown to the doctor.
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
            // How the registration was confirmed, e.g. "Checked SCFHS public register on 2026-10-18".
            'note' => ['nullable', 'string', 'max:2000'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*.id' => ['required', 'uuid', 'distinct'],
            'credentials.*.status' => ['required', Rule::in(['accepted', 'rejected'])],
            'credentials.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
