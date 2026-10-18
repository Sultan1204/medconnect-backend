<?php

namespace App\Modules\Prescriptions\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $prescription_id
 * @property string $catalog_system
 * @property string $catalog_code
 * @property string $medication_name
 * @property string|null $strength
 * @property string|null $dosage_form
 * @property string $dose
 * @property string $route
 * @property string $frequency
 * @property int $duration_days
 * @property int $quantity
 * @property string $quantity_unit
 * @property string|null $instructions
 * @property bool $is_controlled
 * @property int $position
 */
class PrescriptionItem extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const FIELDS = [
        'catalog_system', 'catalog_code', 'medication_name', 'strength', 'dosage_form', 'dose',
        'route', 'frequency', 'duration_days', 'quantity', 'quantity_unit', 'instructions',
    ];

    protected $fillable = [...self::FIELDS, 'prescription_id', 'is_controlled', 'position'];

    protected function casts(): array
    {
        return [
            'instructions' => 'encrypted',
            'duration_days' => 'integer',
            'quantity' => 'integer',
            'is_controlled' => 'boolean',
            'position' => 'integer',
        ];
    }
}
