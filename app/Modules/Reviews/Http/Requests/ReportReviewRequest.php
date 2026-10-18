<?php

namespace App\Modules\Reviews\Http\Requests;

use App\Modules\Reviews\Models\ReviewReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ReviewReport::REASONS)],
            'details' => ['nullable', 'string', 'max:500'],
        ];
    }
}
