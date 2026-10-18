<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable consent event. Withdrawal is recorded as a new row with granted=false.
 *
 * @property string $id
 * @property string $patient_id
 * @property string $consent_type
 * @property string $version
 * @property bool $granted
 * @property string $recorded_by_user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class PatientConsent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'consent_type', 'version', 'granted', 'recorded_by_user_id', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent records are immutable.'));
        static::deleting(fn () => throw new LogicException('Consent records are immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id')->withTrashed();
    }
}
