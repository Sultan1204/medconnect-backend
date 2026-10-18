<?php

namespace App\Modules\Prescriptions\Safety;

use App\Modules\Prescriptions\Contracts\ClinicalSafetyChecker;
use App\Modules\Prescriptions\Data\SafetyCheckResult;
use App\Modules\Prescriptions\Models\Prescription;
use RuntimeException;

/**
 * NOT A CLINICAL SAFETY MECHANISM. Simple string heuristics so the warning /
 * acknowledgement workflow can be developed and tested:
 *  - allergy: an active allergen name appears in a medication name -> warning
 *  - duplicate: the same catalogue code twice in one prescription -> block
 *  - duplication with current medication (name match) -> warning
 */
class MockSafetyChecker implements ClinicalSafetyChecker
{
    public function name(): string
    {
        return 'mock';
    }

    public function check(Prescription $prescription, array $patientAllergies, array $activeMedications): SafetyCheckResult
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock safety checker must not be used in production.');
        }

        $result = new SafetyCheckResult;
        $seen = [];

        foreach ($prescription->items as $index => $item) {
            $name = mb_strtolower($item->medication_name);

            foreach ($patientAllergies as $allergen) {
                if ($allergen !== '' && str_contains($name, mb_strtolower($allergen))) {
                    $result->add('warning', 'allergy', "Recorded allergy \"{$allergen}\" may be relevant to {$item->medication_name}.", $index);
                }
            }

            foreach ($activeMedications as $current) {
                if ($current !== '' && str_contains($name, mb_strtolower($current))) {
                    $result->add('warning', 'therapeutic_duplication', "Patient already takes \"{$current}\".", $index);
                }
            }

            $code = $item->catalog_system.':'.$item->catalog_code;
            if (isset($seen[$code])) {
                $result->add('block', 'duplicate_item', "{$item->medication_name} appears more than once.", $index);
            }
            $seen[$code] = true;
        }

        return $result;
    }
}
