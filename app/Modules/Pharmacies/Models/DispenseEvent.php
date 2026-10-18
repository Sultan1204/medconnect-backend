<?php

namespace App\Modules\Pharmacies\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $dispense_request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $actor_user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class DispenseEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
