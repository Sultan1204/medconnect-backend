<?php

namespace App\Modules\Prescriptions\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only prescription history (DB trigger enforced).
 *
 * @property string $id
 * @property string $prescription_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $actor_user_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class PrescriptionEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
