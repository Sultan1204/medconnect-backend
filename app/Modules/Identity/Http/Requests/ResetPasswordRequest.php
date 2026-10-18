<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'digits:'.config('medconnect.otp.length')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
