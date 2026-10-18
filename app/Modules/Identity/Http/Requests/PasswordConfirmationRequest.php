<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-authentication for sensitive account actions.
 */
class PasswordConfirmationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:200'],
            'code' => ['sometimes', 'nullable', 'string', 'digits:6'],
        ];
    }
}
