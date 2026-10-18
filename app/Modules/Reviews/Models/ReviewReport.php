<?php

namespace App\Modules\Reviews\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's report of a published review for moderation.
 *
 * @property string $id
 * @property string $review_id
 * @property string $reporter_user_id
 * @property string $reason
 * @property string|null $details
 * @property string $status open|actioned|dismissed
 * @property string|null $handled_by_user_id
 * @property Carbon|null $handled_at
 * @property string|null $handling_note
 * @property Carbon $created_at
 * @property-read Review $review
 */
class ReviewReport extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const REASONS = ['inaccurate', 'abusive', 'contains_personal_info', 'spam', 'other'];

    protected $fillable = ['review_id', 'reporter_user_id', 'reason', 'details'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id')->withTrashed();
    }
}
