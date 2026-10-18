<?php

namespace App\Modules\MedicalRecords\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable snapshot of finalised clinical content (DB trigger enforced).
 *
 * @property string $id
 * @property string $clinical_note_id
 * @property int $version
 * @property array<string, string|null> $content
 * @property string $author_user_id
 * @property string|null $change_reason
 * @property Carbon $created_at
 * @property-read User $authorUser
 */
class ClinicalNoteVersion extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['clinical_note_id', 'version', 'content', 'author_user_id', 'change_reason'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'version' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Clinical note versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Clinical note versions are immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id')->withTrashed();
    }
}
