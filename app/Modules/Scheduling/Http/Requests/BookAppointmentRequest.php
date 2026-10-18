<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookAppointmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'uuid'],
            // Omit to book for yourself; set to a dependant's patient id to book on their behalf.
            'patient_id' => ['sometimes', 'nullable', 'uuid'],
            'consultation_type' => ['required', Rule::in(['video', 'chat'])],
            // Exact `starts_at` of a slot from GET /doctors/{id}/availability.
            'starts_at' => ['required', 'date', 'after:now'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && preg_match('/^[A-Za-z0-9\-_]{8,100}$/', $key) ? $key : null;
    }
}
