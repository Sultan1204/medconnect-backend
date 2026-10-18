<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailabilityQueryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('medconnect.scheduling.availability_max_range_days');

        return [
            // Local dates (Asia/Riyadh). Defaults: today .. today + 6 days.
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail) use ($max) {
                $from = $this->input('from', today()->toDateString());
                if (is_string($value) && strtotime($value) - strtotime((string) $from) > ($max - 1) * 86400) {
                    $fail("The date range may not exceed {$max} days.");
                }
            }],
            'consultation_type' => ['sometimes', Rule::in(['video', 'chat'])],
        ];
    }
}
