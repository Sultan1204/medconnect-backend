<?php

namespace App\Modules\Specialties\Http\Requests;

use App\Modules\Specialties\Models\Specialty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpecialtyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Specialty|null $specialty */
        $specialty = $this->route('specialty');
        $required = $specialty === null ? 'required' : 'sometimes';

        return [
            'slug' => [$required, 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('specialties', 'slug')->ignore($specialty?->id)],
            'name_en' => [$required, 'string', 'max:120'],
            'name_ar' => [$required, 'string', 'max:120'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
