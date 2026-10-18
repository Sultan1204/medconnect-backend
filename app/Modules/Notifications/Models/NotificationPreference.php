<?php

namespace App\Modules\Notifications\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's explicit choice for one category on one outbound channel. Absence
 * of a row means "use the platform default".
 *
 * @property string $id
 * @property string $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['user_id', 'category', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
