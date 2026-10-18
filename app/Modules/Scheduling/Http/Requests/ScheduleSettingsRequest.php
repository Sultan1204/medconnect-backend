<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'min_notice_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'max_days_ahead' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'max_daily_appointments' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'auto_confirm' => ['sometimes', 'boolean'],
            'free_cancellation_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'late_cancellation_refund_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
