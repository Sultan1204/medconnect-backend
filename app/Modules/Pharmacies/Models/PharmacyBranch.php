<?php

namespace App\Modules\Pharmacies\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $pharmacy_id
 * @property string $name_en
 * @property string $name_ar
 * @property string $city
 * @property string|null $district
 * @property string $address_line
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $phone
 * @property array<string, mixed>|null $opening_hours
 * @property bool $is_active
 * @property bool $accepts_eprescriptions
 * @property-read Pharmacy $pharmacy
 */
class PharmacyBranch extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = [
        'pharmacy_id', 'name_en', 'name_ar', 'city', 'district', 'address_line',
        'latitude', 'longitude', 'phone', 'opening_hours', 'is_active', 'accepts_eprescriptions',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'opening_hours' => 'array',
            'is_active' => 'boolean',
            'accepts_eprescriptions' => 'boolean',
        ];
    }

    /** @return BelongsTo<Pharmacy, $this> */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class)->withTrashed();
    }

    /**
     * Branches a patient may choose: active, accepting e-prescriptions, of an approved, licensed pharmacy.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEligible(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('accepts_eprescriptions', true)
            ->whereHas('pharmacy', fn (Builder $q) => $q->operational());
    }

    public function isEligible(): bool
    {
        return $this->is_active && $this->accepts_eprescriptions && $this->pharmacy->isOperational();
    }
}
