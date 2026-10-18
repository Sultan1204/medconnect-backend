<?php

namespace App\Modules\Doctors\Contracts;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Verification\VerificationResult;

/**
 * Checks a doctor's professional registration against an authoritative
 * source (e.g. the Saudi Commission for Health Specialties).
 *
 * NO automated integration exists yet. The only implementation is
 * ManualProfessionalVerifier, which defers to administrator review. An
 * adapter for an official source may be added only once authorised access
 * and the official integration specification are available.
 */
interface ProfessionalVerifier
{
    public function verify(Doctor $doctor): VerificationResult;

    /** Identifier recorded as the verification `source` (e.g. "manual"). */
    public function source(): string;
}
