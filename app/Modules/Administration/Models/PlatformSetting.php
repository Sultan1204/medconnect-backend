<?php

namespace App\Modules\Administration\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An operator override of an allow-listed configuration value.
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by_user_id
 * @property Carbon $updated_at
 */
class PlatformSetting extends Model
{
    use StoresUtcDates;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
