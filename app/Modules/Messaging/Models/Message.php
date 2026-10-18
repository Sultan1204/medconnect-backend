<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Consultations\Models\Consultation;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Consultation message. The body is encrypted at rest and must never be
 * logged, broadcast or included in notifications.
 *
 * @property string $id
 * @property string $consultation_id
 * @property string|null $sender_user_id
 * @property string $sender_role
 * @property string $type
 * @property string|null $body
 * @property string|null $client_message_id
 * @property int $seq database-assigned ordering

 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class Message extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['consultation_id', 'sender_user_id', 'sender_role', 'type', 'body', 'client_message_id'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
