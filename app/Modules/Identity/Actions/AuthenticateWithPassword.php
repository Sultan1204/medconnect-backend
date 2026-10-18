<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Data\DeviceInfo;
use App\Modules\Identity\Events\SuspiciousActivityDetected;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TokenIssuer;
use App\Support\Exceptions\ApiException;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password sign-in by phone number or email.
 *
 * Unknown identifiers and wrong passwords produce the same response and
 * comparable timing. Repeated failures lock the account temporarily.
 */
class AuthenticateWithPassword
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $identifier, string $password, DeviceInfo $device): array
    {
        $user = self::findByIdentifier($identifier);

        if ($user === null) {
            // Equalise timing with the "wrong password" path.
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(32)));
            $this->audit->failure('auth.login', null, ['actor' => null, 'metadata' => ['reason' => 'unknown_identifier']]);

            throw $this->invalidCredentials();
        }

        if ($user->isLocked()) {
            $this->audit->failure('auth.login', $user, ['actor' => $user, 'metadata' => ['reason' => 'locked']]);

            throw ApiException::make('too_many_attempts', 'Too many failed attempts. Please try again later or reset your password.', 429);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);

            throw $this->invalidCredentials();
        }

        if (! $user->isActive()) {
            $this->audit->failure('auth.login', $user, ['actor' => $user, 'metadata' => ['reason' => 'inactive']]);

            throw ApiException::forbidden('This account is not active.', 'account_inactive');
        }

        if ($user->phone !== null && ! $user->isPhoneVerified()) {
            throw ApiException::forbidden('Please verify your phone number to continue.', 'phone_not_verified');
        }

        $isNewDevice = $this->isNewDevice($user, $device);

        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $device->ip,
        ])->save();

        $this->audit->record('auth.login', $user, ['actor' => $user, 'metadata' => ['new_device' => $isNewDevice, 'platform' => $device->platform]]);

        if ($isNewDevice) {
            SuspiciousActivityDetected::dispatch($user, SuspiciousActivityDetected::NEW_DEVICE, ['platform' => $device->platform]);
        }

        return $this->tokens->afterPrimaryAuthentication($user, $device);
    }

    public static function findByIdentifier(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', mb_strtolower(trim($identifier)))->first();
        }

        $phone = PhoneNumber::normalize($identifier);

        return $phone === null ? null : User::where('phone', $phone)->first();
    }

    private function registerFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $max = (int) config('medconnect.auth.max_failed_logins');

        if ($attempts >= $max) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes((int) config('medconnect.auth.lockout_minutes')),
            ])->save();

            $this->audit->failure('auth.account_locked', $user, ['actor' => $user]);
            SuspiciousActivityDetected::dispatch($user, SuspiciousActivityDetected::ACCOUNT_LOCKED);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
        $this->audit->failure('auth.login', $user, ['actor' => $user, 'metadata' => ['reason' => 'invalid_password']]);
    }

    /**
     * A device is "new" if neither its device id nor its IP has been seen on
     * one of the user's sessions before.
     */
    private function isNewDevice(User $user, DeviceInfo $device): bool
    {
        if ($user->last_login_at === null) {
            return false; // first sign-in after registration
        }

        $known = AccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where(function ($q) use ($device) {
                $q->where('ip_address', $device->ip);
                if ($device->deviceId !== null) {
                    $q->orWhere('device_id', $device->deviceId);
                }
            })
            ->exists();

        return ! $known && $user->last_login_ip !== $device->ip;
    }

    private function invalidCredentials(): ApiException
    {
        return ApiException::make('invalid_credentials', 'The provided credentials are incorrect.', 401);
    }
}
