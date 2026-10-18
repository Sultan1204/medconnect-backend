<?php

namespace App\Modules\Video\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $video_session_id
 * @property string|null $user_id
 * @property string $type
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class VideoSessionEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    public const TYPES = ['joined', 'left', 'reconnected', 'connection_failed', 'media_permission_denied'];

    protected $fillable = ['video_session_id', 'user_id', 'type', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
