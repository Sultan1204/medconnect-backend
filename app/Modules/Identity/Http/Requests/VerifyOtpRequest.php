<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Data\DeviceInfo;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    use NormalizesPhone;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'code' => ['required', 'string', 'digits:'.config('medconnect.otp.length')],
            ...DeviceInfo::rules(),
        ];
    }
}
