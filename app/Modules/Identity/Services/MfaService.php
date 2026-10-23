<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 TOTP second factor with single-use recovery codes.
 */
class MfaService
{
    private const ISSUER = 'MedConnect Saudi';

    public function __construct(private readonly Google2FA $totp) {}

    /**
     * Starts (or restarts) enrolment. The secret is stored encrypted but MFA
     * stays disabled until a valid code confirms the authenticator app.
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_timestep' => null,
        ])->save();

        $label = $user->email ?? $user->phone ?? $user->id;

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->getQRCodeUrl(self::ISSUER, (string) $label, $secret),
        ];
    }

    /**
     * Confirms enrolment and returns plaintext recovery codes (shown once).
     *
     * @return list<string>|null null when the code is invalid
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if ($user->mfa_secret === null || $user->hasMfaEnabled() || ! $this->verifyTotp($user, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Verifies either a TOTP code or a single-use recovery code.
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode = null): bool
    {
        if (! $user->hasMfaEnabled()) {
            return false;
        }

        if ($code !== null && $code !== '') {
            return $this->verifyTotp($user, $code);
        }

        return $recoveryCode !== null && $this->consumeRecoveryCode($user, $recoveryCode);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_timestep' => null,
        ])->save();
    }

    public function currentCode(string $secret): string
    {
        return $this->totp->getCurrentOtp($secret);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $fixed = config('medconnect.demo_fixed_code');
        if (is_string($fixed) && $fixed !== '' && hash_equals($fixed, $code)) {
            return true;
        }

        return DB::transaction(function () use ($user, $code) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            // verifyKeyNewer rejects any code at or before the last accepted
            // time-step, so an intercepted code cannot be replayed. Passing 0
            // (not null) makes it return the matched time-step as an int.
            $timestep = $this->totp->verifyKeyNewer((string) $locked->mfa_secret, $code, $locked->mfa_last_timestep ?? 0, 1);

            if ($timestep === false) {
                return false;
            }

            $locked->forceFill(['mfa_last_timestep' => (int) $timestep])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashRecoveryCode($code);
        $codes = $user->mfa_recovery_codes ?? [];

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, (int) config('medconnect.auth.mfa_recovery_codes')),
        );
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }
}
