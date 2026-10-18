<?php

namespace App\Modules\MedicalRecords\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Medical history entry (condition, surgery, family/social history).
 *
 * @property string $id
 * @property string $patient_id
 * @property string $type
 * @property string $title
 * @property string|null $details
 * @property string $status active|resolved|entered_in_error
 * @property Carbon|null $onset_date
 * @property string $source patient_reported|clinician
 * @property string $recorded_by_user_id
 * @property string|null $consultation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MedicalRecord extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const TYPES = ['condition', 'chronic_condition', 'surgery', 'family_history', 'social_history', 'other'];

    protected $fillable = ['patient_id', 'type', 'title', 'details', 'status', 'onset_date', 'source', 'recorded_by_user_id', 'consultation_id'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'details' => 'encrypted', 'onset_date' => 'date'];
    }
}
