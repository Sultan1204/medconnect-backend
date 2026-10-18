<?php

namespace App\Modules\Patients\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PDPL data-subject request (access, correction, deletion, restriction).
 *
 * @property string $id
 * @property string $patient_id
 * @property string $requested_by_user_id
 * @property string $type
 * @property string $status
 * @property string|null $details
 * @property string|null $resolution
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
class PatientDataRequest extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const TYPES = ['access', 'correction', 'deletion', 'restriction'];

    protected $fillable = ['patient_id', 'requested_by_user_id', 'type', 'details'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'details' => 'encrypted',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
