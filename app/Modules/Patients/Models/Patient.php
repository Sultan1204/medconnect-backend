<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Policies\PatientPolicy;
use App\Support\Database\StoresUtcDates;
use Database\Factories\Patients\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Patient profile (identity data only - clinical data is in MedicalRecords).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $full_name
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $emergency_contact_relationship
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(PatientFactory::class)]
#[UsePolicy(PatientPolicy::class)]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    use StoresUtcDates;

    protected $fillable = [
        'user_id', 'full_name', 'date_of_birth', 'gender',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'emergency_contact_name' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PatientGuardian, $this> */
    public function guardianLinks(): HasMany
    {
        return $this->hasMany(PatientGuardian::class);
    }

    /** @return HasMany<PatientConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /**
     * Minors (or patients of unknown age) cannot consent on their own.
     * The age threshold is configuration, pending legal confirmation.
     */
    public function isMinor(): bool
    {
        $age = $this->age();

        return $age === null || $age < (int) config('medconnect.patients.age_of_majority');
    }

    public function hasActiveGuardian(User $user): bool
    {
        return $this->guardianLinks()
            ->where('guardian_user_id', $user->id)
            ->where('status', GuardianStatus::Active)
            ->exists();
    }
}
