<?php

namespace App\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:file', 'nullable', 'string', 'max:4000'],
            // Multipart upload. PDF / JPEG / PNG, virus-scanned, stored privately.
            'file' => ['sometimes', 'file', 'mimes:'.implode(',', config('medconnect.consultations.attachment_mimes')), 'max:'.config('medconnect.consultations.attachment_max_kb')],
            // Client-generated id (e.g. a UUID) used to de-duplicate retries.
            'client_message_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
