<?php

namespace App\Modules\Pharmacies\Services;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Support\Exceptions\ApiException;

/**
 * Pharmacy staff see only dispense requests sent to branches they work at,
 * and only while their pharmacy is approved and licensed. They never gain
 * access to consultations or the wider medical record.
 */
class PharmacyAccess
{
    public function staffFor(User $user): ?PharmacyStaff
    {
        if (! $user->hasRole(RoleName::PharmacyStaff->value)) {
            return null;
        }

        $staff = PharmacyStaff::with('pharmacy')->where('user_id', $user->id)->where('is_active', true)->first();

        return $staff !== null && $staff->pharmacy->isOperational() ? $staff : null;
    }

    public function requireStaff(User $user): PharmacyStaff
    {
        return $this->staffFor($user) ?? throw ApiException::forbidden('No active pharmacy assignment.', 'not_pharmacy_staff');
    }

    public function canHandle(PharmacyStaff $staff, DispenseRequest $request): bool
    {
        $request->loadMissing('branch');

        return $request->branch->pharmacy_id === $staff->pharmacy_id && $staff->coversBranch($request->branch_id);
    }

    public function requireHandle(User $user, DispenseRequest $request): PharmacyStaff
    {
        $staff = $this->requireStaff($user);

        if (! $this->canHandle($staff, $request)) {
            // Not found rather than forbidden: do not confirm the request exists.
            throw ApiException::make('not_found', 'The requested resource was not found.', 404);
        }

        return $staff;
    }
}
