<?php

namespace App\Modules\Prescriptions\Contracts;

use App\Modules\Prescriptions\Data\SafetyCheckResult;
use App\Modules\Prescriptions\Models\Prescription;

/**
 * Drug-interaction, allergy and duplication checks.
 *
 * Production MUST use a clinically validated knowledge base (licensed drug
 * database). The mock implementation is a development aid only and is not
 * a clinical safety mechanism.
 */
interface ClinicalSafetyChecker
{
    public function name(): string;

    /**
     * @param  list<string>  $patientAllergies  active allergen names
     * @param  list<string>  $activeMedications  current medication names
     */
    public function check(Prescription $prescription, array $patientAllergies, array $activeMedications): SafetyCheckResult;
}
