<?php

namespace App\Modules\Specialties\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Database\Factories\Specialties\SpecialtyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property bool $is_active
 * @property int $sort_order
 */
#[UseFactory(SpecialtyFactory::class)]
class Specialty extends Model
{
    /** @use HasFactory<SpecialtyFactory> */
    use HasFactory, HasUuids;

    use StoresUtcDates;

    protected $fillable = [
        'slug', 'name_en', 'name_ar', 'description_en', 'description_ar', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsToMany<Doctor, $this> */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_specialties')->withPivot('is_primary')->withTimestamps();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function localizedName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
