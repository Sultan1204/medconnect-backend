<?php

namespace App\Modules\Administration\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step in a complaint's history. `internal` events (handler notes) are
 * never shown to the complainant.
 *
 * @property string $id
 * @property string $complaint_id
 * @property string|null $actor_user_id
 * @property string $type submitted|assigned|status_changed|priority_changed|note
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property string $visibility internal|public
 * @property Carbon $created_at
 */
class ComplaintEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['complaint_id', 'actor_user_id', 'type', 'from_status', 'to_status', 'note', 'visibility'];

    protected function casts(): array
    {
        return [
            'note' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
