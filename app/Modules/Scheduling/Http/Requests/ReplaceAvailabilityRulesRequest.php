<?php

namespace App\Modules\Scheduling\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replaces the doctor's whole weekly schedule atomically.
 */
class ReplaceAvailabilityRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:50', $this->noOverlapsPerDay()],
            // 0 = Sunday ... 6 = Saturday.
            'rules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            // Local time (Asia/Riyadh), 24h "HH:MM".
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i', 'after:rules.*.start_time'],
            'rules.*.slot_minutes' => ['required', 'integer', Rule::in([10, 15, 20, 30, 45, 60, 90, 120])],
            'rules.*.consultation_types' => ['required', 'array', 'min:1'],
            'rules.*.consultation_types.*' => ['string', Rule::in(['video', 'chat']), 'distinct'],
            'rules.*.breaks' => ['sometimes', 'array', 'max:5'],
            'rules.*.breaks.*.start' => ['required', 'date_format:H:i'],
            'rules.*.breaks.*.end' => ['required', 'date_format:H:i', 'after:rules.*.breaks.*.start'],
            'rules.*.valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rules.*.valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:rules.*.valid_from'],
        ];
    }

    private function noOverlapsPerDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $byDay = [];

            foreach (is_array($value) ? $value : [] as $rule) {
                if (! isset($rule['day_of_week'], $rule['start_time'], $rule['end_time'])) {
                    return; // field-level rules report this
                }

                foreach ($byDay[$rule['day_of_week']] ?? [] as [$start, $end]) {
                    if ($rule['start_time'] < $end && $start < $rule['end_time']) {
                        $fail('Availability windows on the same day must not overlap.');

                        return;
                    }
                }

                $byDay[$rule['day_of_week']][] = [$rule['start_time'], $rule['end_time']];
            }
        };
    }
}
