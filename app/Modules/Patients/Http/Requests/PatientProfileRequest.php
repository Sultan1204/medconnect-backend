<?php

namespace App\Modules\Patients\Http\Requests;

use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a dependant (POST) or update a profile (PATCH).
 */
class PatientProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('emergency_contact.phone')) {
            $phone = (string) $this->input('emergency_contact.phone');
            $this->merge(['emergency_contact' => array_merge((array) $this->input('emergency_contact'), [
                'phone' => PhoneNumber::normalize($phone) ?? $phone,
            ])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'min:2', 'max:150'],
            'date_of_birth' => [$required, 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'emergency_contact' => ['sometimes', 'nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:150'],
            'emergency_contact.phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'emergency_contact.relationship' => ['nullable', 'string', 'max:50'],
            // Dependants only: how the signed-in user relates to the patient.
            'relationship' => [$creating ? 'required' : 'prohibited', Rule::in(['parent', 'legal_guardian', 'other'])],
        ];
    }

    /**
     * Validated data mapped to Patient columns.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $data = $this->validated();
        $attributes = array_intersect_key($data, array_flip(['full_name', 'date_of_birth', 'gender']));

        if (array_key_exists('emergency_contact', $data)) {
            $contact = $data['emergency_contact'] ?? [];
            $attributes += [
                'emergency_contact_name' => $contact['name'] ?? null,
                'emergency_contact_phone' => $contact['phone'] ?? null,
                'emergency_contact_relationship' => $contact['relationship'] ?? null,
            ];
        }

        return $attributes;
    }
}
