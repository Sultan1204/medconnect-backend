<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use NormalizesPhone;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::in(RoleName::selfRegistrable())],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => $this->phoneRules(),
            'email' => ['nullable', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'preferred_locale' => ['nullable', Rule::in(config('medconnect.supported_locales'))],
            // Optional patient profile fields (stored by the Patients module).
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
        ];
    }
}
