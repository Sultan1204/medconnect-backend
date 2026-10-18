<?php

namespace App\Modules\Pharmacies\Models;

use App\Modules\Patients\Models\Patient;
use App\Modules\Pharmacies\Enums\DispenseStatus;
use App\Modules\Prescriptions\Models\Prescription;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $prescription_id
 * @property string $patient_id
 * @property string $branch_id
 * @property string $requested_by_user_id
 * @property DispenseStatus $status
 * @property string|null $rejection_reason
 * @property string|null $pharmacy_note
 * @property string|null $handled_by_user_id
 * @property Carbon|null $ready_at
 * @property Carbon|null $dispensed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Prescription $prescription
 * @property-read Patient $patient
 * @property-read PharmacyBranch $branch
 */
class DispenseRequest extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const REJECTION_REASONS = ['out_of_stock', 'prescription_invalid', 'requires_clarification', 'patient_not_present', 'other'];

    protected $fillable = ['prescription_id', 'patient_id', 'branch_id', 'requested_by_user_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => DispenseStatus::class,
            'ready_at' => 'datetime',
            'dispensed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<PharmacyBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class);
    }

    /** @return HasMany<DispenseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DispenseEvent::class)->orderBy('created_at');
    }
}
