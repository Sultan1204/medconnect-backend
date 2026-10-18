<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Data\DeviceInfo;
use Illuminate\Foundation\Http\FormRequest;

class MfaCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 6-digit code from the authenticator app.
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            // One of the single-use recovery codes (challenge only).
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
            ...DeviceInfo::rules(),
        ];
    }
}
