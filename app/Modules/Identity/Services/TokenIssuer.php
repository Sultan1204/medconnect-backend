<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Data\DeviceInfo;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Issues bearer tokens. A user whose role requires MFA (or who opted in)
 * only ever receives a restricted, short-lived token until the second factor
 * is completed or enrolled.
 */
class TokenIssuer
{
    /**
     * Token for a user who has proven their first factor (password or OTP).
     *
     * @return array<string, mixed>
     */
    public function afterPrimaryAuthentication(User $user, DeviceInfo $device): array
    {
        if ($user->hasMfaEnabled()) {
            $ttl = (int) config('medconnect.auth.mfa_challenge_ttl_minutes');
            [$plain, $expires] = $this->create($user, $device, [AccessToken::ABILITY_MFA_CHALLENGE], now()->addMinutes($ttl));

            return ['status' => 'mfa_required', 'mfa_token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601ZuluString()];
        }

        if ($user->requiresMfa()) {
            $ttl = (int) config('medconnect.auth.mfa_enrollment_ttl_minutes');
            [$plain, $expires] = $this->create($user, $device, [AccessToken::ABILITY_MFA_ENROLL], now()->addMinutes($ttl));

            return ['status' => 'mfa_enrollment_required', 'mfa_token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601ZuluString()];
        }

        return $this->full($user, $device);
    }

    /**
     * Fully privileged token - only after every required factor is satisfied.
     *
     * @return array<string, mixed>
     */
    public function full(User $user, DeviceInfo $device): array
    {
        $ttl = (int) config('medconnect.auth.token_ttl_minutes');
        [$plain, $expires] = $this->create($user, $device, [AccessToken::ABILITY_FULL], now()->addMinutes($ttl));

        return [
            'status' => 'authenticated',
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601ZuluString(),
            'user' => new UserResource($user->fresh()),
        ];
    }

    /**
     * @param  list<string>  $abilities
     * @return array{string, Carbon}
     */
    private function create(User $user, DeviceInfo $device, array $abilities, Carbon $expiresAt): array
    {
        $plain = $user->generateTokenString();

        /** @var AccessToken $token */
        $token = $user->tokens()->create([
            'name' => $device->name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'device_id' => $device->deviceId,
            'platform' => $device->platform,
            'ip_address' => $device->ip,
            'user_agent' => $device->userAgent,
        ]);

        return [$token->getKey().'|'.$plain, $expiresAt];
    }
}
