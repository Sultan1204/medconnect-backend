<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $doctor_id
 * @property int $min_notice_minutes
 * @property int $max_days_ahead
 * @property int $buffer_minutes
 * @property int|null $max_daily_appointments
 * @property bool $auto_confirm
 * @property int $free_cancellation_hours
 * @property int $late_cancellation_refund_percent
 */
class DoctorScheduleSettings extends Model
{
    use StoresUtcDates;

    protected $primaryKey = 'doctor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'doctor_id', 'min_notice_minutes', 'max_days_ahead', 'buffer_minutes', 'max_daily_appointments',
        'auto_confirm', 'free_cancellation_hours', 'late_cancellation_refund_percent',
    ];

    /** Defaults mirror the column defaults so unsaved settings behave identically. */
    protected $attributes = [
        'min_notice_minutes' => 60,
        'max_days_ahead' => 30,
        'buffer_minutes' => 0,
        'max_daily_appointments' => null,
        'auto_confirm' => true,
        'free_cancellation_hours' => 24,
        'late_cancellation_refund_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'min_notice_minutes' => 'integer',
            'max_days_ahead' => 'integer',
            'buffer_minutes' => 'integer',
            'max_daily_appointments' => 'integer',
            'auto_confirm' => 'boolean',
            'free_cancellation_hours' => 'integer',
            'late_cancellation_refund_percent' => 'integer',
        ];
    }

    public static function for(Doctor $doctor): self
    {
        return self::firstOrNew(['doctor_id' => $doctor->id]);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * The policy copied onto each appointment at booking time.
     *
     * @return array{free_cancellation_hours: int, late_cancellation_refund_percent: int}
     */
    public function cancellationPolicy(): array
    {
        return [
            'free_cancellation_hours' => $this->free_cancellation_hours,
            'late_cancellation_refund_percent' => $this->late_cancellation_refund_percent,
        ];
    }
}
