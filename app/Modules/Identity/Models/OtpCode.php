<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $user_id
 * @property OtpPurpose $purpose
 * @property string $channel
 * @property string $destination_hash
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class OtpCode extends Model
{
    use HasUuids, Prunable;
    use StoresUtcDates;

    /**
     * Expired codes are deleted a day after expiry.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'destination_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('medconnect.otp.max_attempts');
    }
}
