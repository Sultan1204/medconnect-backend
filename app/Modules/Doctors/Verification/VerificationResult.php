<?php

namespace App\Modules\Doctors\Verification;

final readonly class VerificationResult
{
    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public const VERIFIED = 'verified';

    public const NOT_FOUND = 'not_found';

    public const MISMATCH = 'mismatch';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $details  non-sensitive facts returned by the source
     */
    public function __construct(
        public string $outcome,
        public ?string $reference = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'reference' => $this->reference, 'details' => $this->details];
    }
}
