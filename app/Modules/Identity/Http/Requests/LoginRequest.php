<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Data\DeviceInfo;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Phone number (any common Saudi format or E.164) or email address.
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
            ...DeviceInfo::rules(),
        ];
    }
}
