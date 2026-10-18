<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Date-specific availability: an extra bookable window ("available") or
 * time off ("blocked"), stored in UTC.
 *
 * @property string $id
 * @property string $doctor_id
 * @property string $type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $slot_minutes
 * @property list<string>|null $consultation_types
 * @property string|null $note
 */
class AvailabilitySlot extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const AVAILABLE = 'available';

    public const BLOCKED = 'blocked';

    protected $fillable = ['doctor_id', 'type', 'starts_at', 'ends_at', 'slot_minutes', 'consultation_types', 'note'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'slot_minutes' => 'integer',
            'consultation_types' => 'array',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
