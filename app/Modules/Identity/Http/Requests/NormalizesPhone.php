<?php

namespace App\Modules\Identity\Http\Requests;

use App\Support\Phone\PhoneNumber;

/**
 * Converts the `phone` input to E.164 before validation runs.
 */
trait NormalizesPhone
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize((string) $this->input('phone')) ?? $this->input('phone')]);
        }
    }

    /** @return list<string> */
    protected function phoneRules(): array
    {
        return ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'];
    }
}
