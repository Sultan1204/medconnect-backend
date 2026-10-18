<?php

namespace App\Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['publish', 'hide', 'reject'])],
            // Taking content down must be explained.
            'note' => [Rule::requiredIf(fn () => in_array($this->input('decision'), ['hide', 'reject'], true)), 'nullable', 'string', 'max:500'],
        ];
    }
}
