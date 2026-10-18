<?php

namespace App\Modules\Prescriptions\Models;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $consultation_id
 * @property string $patient_id
 * @property string $prescriber_doctor_id
 * @property string $prescriber_user_id
 * @property PrescriptionStatus $status
 * @property string|null $clinical_indication
 * @property string $safety_check_status not_run|passed|warnings|blocked
 * @property list<array{severity: string, type: string, message: string, item_index: int|null}>|null $safety_check_result
 * @property string|null $warnings_justification
 * @property Carbon|null $warnings_acknowledged_at
 * @property string|null $gateway
 * @property string|null $external_reference
 * @property bool $is_simulated
 * @property Carbon|null $submitted_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $cancelled_at
 * @property string|null $status_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Consultation $consultation
 * @property-read Patient $patient
 * @property-read Doctor $prescriber
 * @property-read Collection<int, PrescriptionItem> $items
 */
class Prescription extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['consultation_id', 'patient_id', 'prescriber_doctor_id', 'prescriber_user_id', 'clinical_indication'];

    protected $attributes = ['status' => 'draft', 'safety_check_status' => 'not_run', 'is_simulated' => false];

    protected function casts(): array
    {
        return [
            'status' => PrescriptionStatus::class,
            'clinical_indication' => 'encrypted',
            'safety_check_result' => 'encrypted:array',
            'warnings_justification' => 'encrypted',
            'warnings_acknowledged_at' => 'datetime',
            'is_simulated' => 'boolean',
            'submitted_at' => 'datetime',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<Doctor, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'prescriber_doctor_id')->withTrashed();
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('position');
    }

    /** @return HasMany<PrescriptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PrescriptionEvent::class)->orderBy('created_at');
    }

    /**
     * Valid for dispensing: issued and not expired. Simulated (mock-gateway)
     * prescriptions only count where explicitly allowed - never in production.
     */
    public function isDispensable(): bool
    {
        $simulatedAllowed = (bool) config('medconnect.prescriptions.allow_simulated_dispensing') && ! app()->isProduction();

        return $this->status === PrescriptionStatus::Issued
            && (! $this->is_simulated || $simulatedAllowed)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * What clients must display about the document's legal standing.
     */
    public function legalValidity(): string
    {
        return match (true) {
            $this->status === PrescriptionStatus::Issued && $this->is_simulated => 'simulated_not_valid',
            in_array($this->status, [PrescriptionStatus::Issued, PrescriptionStatus::Dispensed], true) => 'issued_by_authorized_service',
            default => 'not_valid',
        };
    }
}
