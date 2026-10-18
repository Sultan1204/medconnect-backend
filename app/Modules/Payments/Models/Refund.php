<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payment_id
 * @property int $amount
 * @property string $currency
 * @property string $status pending|succeeded|failed
 * @property string $reason cancellation|late_payment|admin|dispute
 * @property string|null $note
 * @property string|null $provider_refund_id
 * @property string $idempotency_key
 * @property string|null $requested_by_user_id
 * @property Carbon|null $succeeded_at
 * @property string|null $failure_code
 * @property Carbon $created_at
 * @property-read Payment $payment
 */
class Refund extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['payment_id', 'amount', 'currency', 'reason', 'note', 'idempotency_key', 'requested_by_user_id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'succeeded_at' => 'datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
