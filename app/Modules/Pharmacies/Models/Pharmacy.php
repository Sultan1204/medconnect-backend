<?php

namespace App\Modules\Pharmacies\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name_en
 * @property string $name_ar
 * @property string $license_number
 * @property string $licensing_authority
 * @property Carbon $license_expires_on
 * @property string|null $commercial_registration
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property string $verification_status pending|approved|rejected|suspended
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $status_reason
 * @property Carbon $created_at
 */
class Pharmacy extends Model
{
    use HasUuids, SoftDeletes;
    use StoresUtcDates;

    protected $fillable = [
        'name_en', 'name_ar', 'license_number', 'licensing_authority', 'license_expires_on',
        'commercial_registration', 'contact_phone', 'contact_email',
    ];

    protected $attributes = ['verification_status' => 'pending'];

    protected function casts(): array
    {
        return ['license_expires_on' => 'date', 'verified_at' => 'datetime'];
    }

    /** @return HasMany<PharmacyBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(PharmacyBranch::class);
    }

    /** @return HasMany<PharmacyStaff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(PharmacyStaff::class);
    }

    public function isOperational(): bool
    {
        return $this->verification_status === 'approved' && $this->license_expires_on->endOfDay()->isFuture();
    }

    /** @param Builder<self> $query */
    public function scopeOperational(Builder $query): void
    {
        $query->where('verification_status', 'approved')->whereDate('license_expires_on', '>=', today());
    }
}
