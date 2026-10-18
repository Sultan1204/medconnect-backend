<?php

namespace App\Modules\Messaging\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $message_id
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property Carbon $created_at
 */
class MessageAttachment extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'file_path', 'file_name', 'mime_type', 'size_bytes', 'sha256'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
