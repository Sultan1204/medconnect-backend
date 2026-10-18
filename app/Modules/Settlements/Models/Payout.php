<?php

namespace App\Modules\Settlements\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $doctor_id
 * @property int $amount
 * @property string $currency
 * @property string $status pending|processing|paid|failed|cancelled
 * @property string $requested_by_user_id
 * @property string|null $executed_by_user_id
 * @property string|null $provider_payout_id
 * @property Carbon|null $executed_at
 * @property string|null $failure_reason
 * @property Carbon $created_at
 */
class Payout extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['doctor_id', 'amount', 'currency', 'requested_by_user_id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'executed_at' => 'datetime'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }
}
