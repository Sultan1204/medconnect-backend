<?php

namespace App\Modules\Video\Models;

use App\Modules\Consultations\Models\Consultation;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Technical metadata of a video room - kept separate from clinical documentation.
 *
 * @property string $id
 * @property string $consultation_id
 * @property string $provider
 * @property string $provider_room_id
 * @property string $status created|active|ended|failed
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $expires_at
 * @property string|null $last_error
 * @property-read Consultation $consultation
 */
class VideoSession extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['consultation_id', 'provider', 'provider_room_id', 'status', 'expires_at'];

    protected $hidden = ['provider_room_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return HasMany<VideoSessionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(VideoSessionEvent::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['created', 'active'], true) && $this->expires_at->isFuture();
    }
}
