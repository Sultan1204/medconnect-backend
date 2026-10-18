<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Policies\AppointmentPolicy;
use App\Support\Database\StoresUtcDates;
use Database\Factories\Scheduling\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $patient_id
 * @property string $doctor_id
 * @property string $booked_by_user_id
 * @property string $consultation_type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property AppointmentStatus $status
 * @property int $price_amount
 * @property string $currency
 * @property array{free_cancellation_hours: int, late_cancellation_refund_percent: int} $cancellation_policy
 * @property Carbon|null $payment_due_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancelled_by_role
 * @property string|null $cancellation_reason
 * @property int|null $refund_percent
 * @property Carbon|null $no_show_at
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 */
#[UseFactory(AppointmentFactory::class)]
#[UsePolicy(AppointmentPolicy::class)]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUuids;

    use StoresUtcDates;

    protected $fillable = [
        'patient_id', 'doctor_id', 'booked_by_user_id', 'consultation_type', 'starts_at', 'ends_at',
        'status', 'price_amount', 'currency', 'cancellation_policy', 'payment_due_at', 'confirmed_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price_amount' => 'integer',
            'cancellation_policy' => 'array',
            'payment_due_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'refund_percent' => 'integer',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id')->withTrashed();
    }

    public function isFree(): bool
    {
        return $this->price_amount === 0;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::active());
    }
}
