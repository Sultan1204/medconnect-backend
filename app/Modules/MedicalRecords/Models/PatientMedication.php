<?php

namespace App\Modules\MedicalRecords\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Medication the patient is (or was) taking. Not a prescription.
 *
 * @property string $id
 * @property string $patient_id
 * @property string $name
 * @property string|null $dosage
 * @property string|null $frequency
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string $status active|stopped|entered_in_error
 * @property string $source
 * @property string $recorded_by_user_id
 * @property string|null $consultation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PatientMedication extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['patient_id', 'name', 'dosage', 'frequency', 'started_on', 'ended_on', 'status', 'source', 'recorded_by_user_id', 'consultation_id'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'dosage' => 'encrypted',
            'frequency' => 'encrypted',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }
}
