<?php

namespace App\Modules\Doctors\Verification;

use App\Modules\Doctors\Contracts\ProfessionalVerifier;
use App\Modules\Doctors\Models\Doctor;

/**
 * Default: no external lookup. An administrator verifies the registration
 * (e.g. against the official public register) and records the decision.
 */
class ManualProfessionalVerifier implements ProfessionalVerifier
{
    public function verify(Doctor $doctor): VerificationResult
    {
        return new VerificationResult(VerificationResult::MANUAL_REVIEW_REQUIRED);
    }

    public function source(): string
    {
        return 'manual';
    }
}
