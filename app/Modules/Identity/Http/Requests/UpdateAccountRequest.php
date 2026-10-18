<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:191'],
            'preferred_locale' => ['sometimes', Rule::in(config('medconnect.supported_locales'))],
        ];
    }
}
