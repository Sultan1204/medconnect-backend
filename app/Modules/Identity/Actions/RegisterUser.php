<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use Illuminate\Support\Facades\DB;

/**
 * Self-registration for patients and doctors.
 *
 * The response is identical whether or not the phone number is already
 * registered, so the endpoint cannot be used to enumerate accounts. If the
 * number belongs to a verified account, its owner receives a notice instead
 * of a code. An unverified registration can be re-claimed by whoever proves
 * ownership of the number via OTP.
 */
class RegisterUser
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, phone: string, email?: string|null, password: string,
     *               preferred_locale?: string|null, account_type: string,
     *               date_of_birth?: string|null, gender?: string|null}  $data
     */
    public function handle(array $data): void
    {
        $locale = $data['preferred_locale'] ?? app()->getLocale();

        $result = DB::transaction(function () use ($data, $locale) {
            /** @var User|null $existing */
            $existing = User::withTrashed()->where('phone', $data['phone'])->lockForUpdate()->first();

            if ($existing !== null && ($existing->isPhoneVerified() || $existing->trashed())) {
                $this->audit->record('auth.register.duplicate_attempt', $existing, ['actor' => null]);

                return ['duplicate' => true, 'user' => $existing];
            }

            $email = $data['email'] ?? null;
            if ($email !== null && User::withTrashed()->where('email', mb_strtolower($email))->whereKeyNot($existing?->id)->exists()) {
                // Do not reveal that the address is in use; the user can add an email later.
                $email = null;
            }

            $user = $existing ?? new User;
            $user->fill([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $email,
                'password' => $data['password'],
                'preferred_locale' => $locale,
            ]);
            $user->email_verified_at = null;
            $user->save();
            $user->syncRoles([$data['account_type']]);

            $this->audit->record('auth.register', $user, [
                'actor' => $user,
                'metadata' => ['account_type' => $data['account_type'], 'reclaimed_unverified' => $existing !== null],
            ]);

            return ['duplicate' => false, 'user' => $user];
        });

        /** @var User $user */
        $user = $result['user'];

        if ($result['duplicate']) {
            $this->otp->notice(OtpPurpose::Registration, 'sms', $data['phone'], 'already_registered', $user->preferred_locale);

            return;
        }

        UserRegistered::dispatch($user, $data['account_type'], [
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
        ]);

        $this->otp->issue(OtpPurpose::Registration, 'sms', $data['phone'], $user);

        if ($user->email !== null) {
            $this->otp->issue(OtpPurpose::EmailVerification, 'email', $user->email, $user);
        }
    }
}
