<?php

namespace App\Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.config('medconnect.reviews.comment_max_length')],
        ];
    }
}
