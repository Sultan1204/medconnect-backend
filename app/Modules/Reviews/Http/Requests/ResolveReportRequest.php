<?php

namespace App\Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['dismiss', 'hide_review'])],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
