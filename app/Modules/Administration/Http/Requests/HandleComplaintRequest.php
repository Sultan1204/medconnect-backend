<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Administration\Enums\ComplaintStatus;
use App\Modules\Administration\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HandleComplaintRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'priority' => ['sometimes', Rule::in(Complaint::PRIORITIES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
