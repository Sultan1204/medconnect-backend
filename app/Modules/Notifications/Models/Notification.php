<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Enums\NotificationType;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * In-app notification. `params` contains identifiers and non-clinical values
 * only; text is rendered from language files by NotificationRenderer.
 *
 * @property string $id
 * @property string $user_id
 * @property NotificationType $type
 * @property NotificationCategory $category
 * @property array<string, mixed> $params
 * @property string|null $dedupe_key
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class Notification extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['user_id', 'type', 'category', 'params', 'dedupe_key'];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'category' => NotificationCategory::class,
            'params' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
