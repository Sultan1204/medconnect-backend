<?php

namespace App\Modules\MedicalRecords\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $patient_id
 * @property string $allergen
 * @property string|null $reaction
 * @property string $severity mild|moderate|severe|unknown
 * @property string $status active|inactive|entered_in_error
 * @property string $source
 * @property string $recorded_by_user_id
 * @property string|null $consultation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PatientAllergy extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['patient_id', 'allergen', 'reaction', 'severity', 'status', 'source', 'recorded_by_user_id', 'consultation_id'];

    protected function casts(): array
    {
        return ['allergen' => 'encrypted', 'reaction' => 'encrypted'];
    }
}
