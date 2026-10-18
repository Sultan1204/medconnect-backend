<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable record of a verification decision: who, when, why, on what evidence.
 *
 * @property string $id
 * @property string $doctor_id
 * @property string $action
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $evidence
 * @property string $source
 * @property Carbon $created_at
 */
class DoctorVerification extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['doctor_id', 'action', 'from_status', 'to_status', 'actor_id', 'reason', 'evidence', 'source'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification history is immutable.'));
        static::deleting(fn () => throw new LogicException('Verification history is immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
