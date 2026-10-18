<?php

namespace App\Modules\Notifications\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A device registered to receive push notifications. The token is a
 * credential for pushing to the device and is encrypted at rest.
 *
 * @property string $id
 * @property string $user_id
 * @property string $platform android|ios|web
 * @property string $token
 * @property string $token_hash
 * @property string|null $device_name
 * @property Carbon|null $last_used_at
 */
class PushDevice extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = ['user_id', 'platform', 'token', 'token_hash', 'device_name'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
