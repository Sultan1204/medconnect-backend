<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Link between a guardian's account and a patient they may act for.
 *
 * @property string $id
 * @property string $patient_id
 * @property string $guardian_user_id
 * @property string $relationship
 * @property GuardianStatus $status
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $revoked_at
 * @property string|null $status_reason
 * @property Carbon $created_at
 */
class PatientGuardian extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['patient_id', 'guardian_user_id', 'relationship', 'status'];

    protected function casts(): array
    {
        return [
            'status' => GuardianStatus::class,
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }
}
