<?php

namespace App\Modules\Prescriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrescriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $max = (int) config('medconnect.prescriptions.max_items');

        return [
            'consultation_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'clinical_indication' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', "max:{$max}"],
            // Coded entry from the medication catalogue (e.g. SFDA drug code).
            'items.*.catalog_system' => ['required', Rule::in(config('medconnect.prescriptions.catalog_systems'))],
            'items.*.catalog_code' => ['required', 'string', 'max:60'],
            'items.*.medication_name' => ['required', 'string', 'max:200'],
            'items.*.strength' => ['nullable', 'string', 'max:60'],
            'items.*.dosage_form' => ['nullable', 'string', 'max:60'],
            'items.*.dose' => ['required', 'string', 'max:60'],
            'items.*.route' => ['required', Rule::in(['oral', 'topical', 'inhalation', 'ophthalmic', 'otic', 'nasal', 'rectal', 'vaginal', 'subcutaneous', 'intramuscular', 'transdermal', 'sublingual'])],
            'items.*.frequency' => ['required', 'string', 'max:60'],
            'items.*.duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.quantity_unit' => ['required', 'string', 'max:30'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
