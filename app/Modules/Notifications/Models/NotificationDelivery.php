<?php

namespace App\Modules\Notifications\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Outcome of delivering one notification over one outbound channel.
 * Never stores the rendered message or the destination address.
 *
 * @property string $id
 * @property string $notification_id
 * @property string $channel
 * @property string $status pending|sent|failed|skipped
 * @property int $attempts
 * @property string|null $provider_reference
 * @property string|null $error_code
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property-read Notification $notification
 */
class NotificationDelivery extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = ['notification_id', 'channel', 'status'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
