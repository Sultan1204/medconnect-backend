<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Enums\CredentialType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CredentialType::class)],
            'title' => ['required', 'string', 'max:200'],
            'issuer' => ['nullable', 'string', 'max:200'],
            'issued_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:issued_on'],
            // `mimes` checks the detected content type, not just the extension.
            'file' => ['required', 'file', 'mimes:'.implode(',', config('medconnect.files.credential_mimes')), 'max:'.config('medconnect.files.max_upload_kb')],
        ];
    }
}
