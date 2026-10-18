<?php

namespace App\Modules\Consultations\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $consultation_id
 * @property string|null $raised_by_user_id
 * @property string $source
 * @property string $type
 * @property string|null $details
 * @property Carbon|null $acknowledged_at
 * @property Carbon $created_at
 */
class ConsultationEscalation extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $fillable = ['consultation_id', 'raised_by_user_id', 'source', 'type', 'details'];

    protected function casts(): array
    {
        return ['details' => 'encrypted', 'acknowledged_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
