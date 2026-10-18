<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Services\ConsentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ConsentService::types())],
            // Must match the version the client displayed, so consent is to the exact text shown.
            'version' => ['required', 'string', 'max:20'],
            'granted' => ['required', 'boolean'],
        ];
    }
}
