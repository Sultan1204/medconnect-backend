<?php

namespace App\Modules\Pharmacies\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pharmacies are onboarded by administrators (B2B agreement + licence
 * verification), not by public self-registration.
 */
class PharmacyOnboarding
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Creates the staff member's account with an unusable random password and
     * sends a password-setup code. MFA enrolment is enforced at first sign-in.
     *
     * @param  array{name: string, phone: string, email?: string|null, role: string, branch_id?: string|null}  $data
     */
    public function addStaff(Pharmacy $pharmacy, array $data, User $admin): PharmacyStaff
    {
        if (isset($data['branch_id']) && ! $pharmacy->branches()->whereKey($data['branch_id'])->exists()) {
            throw ApiException::make('branch_not_in_pharmacy', 'The branch does not belong to this pharmacy.');
        }

        $staff = DB::transaction(function () use ($pharmacy, $data, $admin) {
            $user = User::where('phone', $data['phone'])->first();

            if ($user !== null && ! $user->hasRole(RoleName::PharmacyStaff->value)) {
                throw ApiException::conflict('account_in_use', 'This phone number belongs to an account that cannot be assigned to a pharmacy.');
            }

            if ($user === null) {
                $user = new User(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'password' => Str::random(64)]);
                $user->phone_verified_at = now();
                $user->save();
                $user->assignRole(RoleName::PharmacyStaff->value);
            }

            $staff = PharmacyStaff::create([
                'pharmacy_id' => $pharmacy->id,
                'branch_id' => $data['branch_id'] ?? null,
                'user_id' => $user->id,
                'role' => $data['role'],
                'is_active' => true,
            ]);

            $this->audit->record('pharmacies.staff_added', $staff, ['actor' => $admin, 'metadata' => ['role' => $data['role']]]);

            return $staff;
        });

        $this->otp->issue(OtpPurpose::PasswordReset, 'sms', $data['phone'], $staff->user);

        return $staff;
    }

    public function decide(Pharmacy $pharmacy, string $decision, User $admin, ?string $reason): Pharmacy
    {
        $allowed = match ($decision) {
            'approve' => in_array($pharmacy->verification_status, ['pending', 'suspended', 'rejected'], true),
            'reject' => $pharmacy->verification_status === 'pending',
            'suspend' => $pharmacy->verification_status === 'approved',
            default => false,
        };

        if (! $allowed) {
            throw ApiException::conflict('invalid_state_transition', "Cannot {$decision} a {$pharmacy->verification_status} pharmacy.");
        }

        if ($decision === 'approve' && $pharmacy->license_expires_on->endOfDay()->isPast()) {
            throw ApiException::make('license_expired', 'The pharmacy licence has expired.');
        }

        $from = $pharmacy->verification_status;
        $pharmacy->forceFill([
            'verification_status' => match ($decision) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'suspended',
            },
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'status_reason' => $reason,
        ])->save();

        $this->audit->record('pharmacies.'.$decision, $pharmacy, [
            'actor' => $admin,
            'changes' => ['verification_status' => [$from, $pharmacy->verification_status]],
        ]);

        return $pharmacy;
    }
}
