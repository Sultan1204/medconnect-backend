<?php

namespace App\Modules\Identity\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum token with device metadata; each row is one signed-in device.
 * `public_id` is exposed to clients so the numeric key never leaves the API.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $device_id
 * @property string|null $platform
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class AccessToken extends PersonalAccessToken
{
    use StoresUtcDates;

    protected $table = 'personal_access_tokens';

    public const ABILITY_FULL = '*';

    public const ABILITY_MFA_CHALLENGE = 'mfa:challenge';

    public const ABILITY_MFA_ENROLL = 'mfa:enroll';

    protected $fillable = [
        'name', 'token', 'abilities', 'expires_at',
        'device_id', 'platform', 'ip_address', 'user_agent',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $token) {
            $token->public_id ??= (string) Str::uuid7();
        });
    }
}
