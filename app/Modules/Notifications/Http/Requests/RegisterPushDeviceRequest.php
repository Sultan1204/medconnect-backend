<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPushDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(PushDevice::PLATFORMS)],
            'token' => ['required', 'string', 'min:10', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
