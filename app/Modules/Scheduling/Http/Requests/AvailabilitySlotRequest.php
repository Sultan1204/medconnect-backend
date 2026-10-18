<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\AvailabilitySlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Extra availability or time off for specific dates.
 */
class AvailabilitySlotRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([AvailabilitySlot::AVAILABLE, AvailabilitySlot::BLOCKED])],
            // ISO-8601 with offset, e.g. 2026-11-02T09:00:00+03:00
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'slot_minutes' => ['required_if:type,available', 'nullable', 'integer', Rule::in([10, 15, 20, 30, 45, 60, 90, 120])],
            'consultation_types' => ['required_if:type,available', 'nullable', 'array', 'min:1'],
            'consultation_types.*' => ['string', Rule::in(['video', 'chat']), 'distinct'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
