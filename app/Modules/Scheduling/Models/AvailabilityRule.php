<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recurring weekly window in the doctor's local time (Asia/Riyadh).
 *
 * @property string $id
 * @property string $doctor_id
 * @property int $day_of_week 0 = Sunday ... 6 = Saturday
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property int $slot_minutes
 * @property list<array{start: string, end: string}> $breaks
 * @property list<string> $consultation_types
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 */
class AvailabilityRule extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = [
        'doctor_id', 'day_of_week', 'start_time', 'end_time', 'slot_minutes',
        'breaks', 'consultation_types', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_minutes' => 'integer',
            'breaks' => 'array',
            'consultation_types' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function appliesOn(\DateTimeInterface $localDate): bool
    {
        $date = $localDate->format('Y-m-d');

        return (int) $localDate->format('w') === $this->day_of_week
            && ($this->valid_from === null || $this->valid_from->toDateString() <= $date)
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $date);
    }
}
