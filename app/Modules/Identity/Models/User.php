<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Enums\UserStatus;
use App\Support\Database\StoresUtcDates;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Authentication identity. Profile and clinical data live in the Patients,
 * Doctors and MedicalRecords modules.
 *
 * @property string $id
 * @property string $name
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $preferred_locale
 * @property UserStatus $status
 * @property Carbon|null $status_changed_at
 * @property string|null $status_reason
 * @property string|null $mfa_secret
 * @property Carbon|null $mfa_enabled_at
 * @property list<string>|null $mfa_recovery_codes
 * @property int|null $mfa_last_timestep
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $password_changed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    use StoresUtcDates;

    protected string $guard_name = 'web';

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'preferred_locale',
    ];

    protected $hidden = [
        'password', 'mfa_secret', 'mfa_recovery_codes',
    ];

    protected $attributes = [
        'status' => 'active',
        'preferred_locale' => 'ar',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Emails are stored lower-cased so lookups and uniqueness are case-insensitive.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_secret !== null;
    }

    public function requiresMfa(): bool
    {
        return $this->hasAnyRole(config('medconnect.auth.mfa_required_roles'));
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Most privileged role, used for audit attribution.
     */
    public function primaryRole(): ?string
    {
        $names = $this->getRoleNames();

        foreach ([RoleName::SuperAdmin, RoleName::Admin, RoleName::PharmacyStaff, RoleName::Doctor, RoleName::Patient] as $role) {
            if ($names->contains($role->value)) {
                return $role->value;
            }
        }

        return null;
    }

    public function isAdministrator(): bool
    {
        return $this->hasAnyRole([RoleName::Admin->value, RoleName::SuperAdmin->value]);
    }
}
