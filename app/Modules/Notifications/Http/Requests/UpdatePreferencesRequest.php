<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:30'],
            'preferences.*.category' => ['required', Rule::in(NotificationCategory::values())],
            'preferences.*.channel' => ['required', Rule::in(NotificationChannel::values())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
