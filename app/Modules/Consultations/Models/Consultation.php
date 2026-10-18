<?php

namespace App\Modules\Consultations\Models;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Policies\ConsultationPolicy;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * @property string $id
 * @property string $appointment_id
 * @property string $patient_id
 * @property string $doctor_id
 * @property string $consultation_type
 * @property ConsultationStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $interrupted_at
 * @property int $max_duration_minutes
 * @property string|null $intake_reason
 * @property string|null $intake_details
 * @property array<string, bool>|null $intake_screening
 * @property Carbon|null $intake_submitted_at
 * @property bool $emergency_flagged
 * @property Carbon $created_at
 * @property-read Appointment $appointment
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 */
#[UsePolicy(ConsultationPolicy::class)]
class Consultation extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const ROLE_PATIENT = 'patient';

    public const ROLE_DOCTOR = 'doctor';

    protected $fillable = [
        'appointment_id', 'patient_id', 'doctor_id', 'consultation_type', 'status', 'max_duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'interrupted_at' => 'datetime',
            'max_duration_minutes' => 'integer',
            'intake_reason' => 'encrypted',
            'intake_details' => 'encrypted',
            'intake_screening' => 'array',
            'intake_submitted_at' => 'datetime',
            'emergency_flagged' => 'boolean',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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

    /** @return HasMany<ConsultationEscalation, $this> */
    public function escalations(): HasMany
    {
        return $this->hasMany(ConsultationEscalation::class);
    }

    /**
     * "doctor", "patient" (the patient or a verified guardian) or null.
     */
    public function participantRole(User $user): ?string
    {
        if ($this->doctor->user_id === $user->id) {
            return self::ROLE_DOCTOR;
        }

        return Gate::forUser($user)->allows('actFor', $this->patient) ? self::ROLE_PATIENT : null;
    }

    /** Hard stop: booked end time plus the configured overtime grace. */
    public function hardEndsAt(): Carbon
    {
        return $this->appointment->ends_at->copy()->addMinutes((int) config('medconnect.consultations.overtime_grace_minutes'));
    }
}
