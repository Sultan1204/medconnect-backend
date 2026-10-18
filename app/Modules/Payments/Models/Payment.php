<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Policies\PaymentPolicy;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $appointment_id
 * @property string $patient_id
 * @property string $payer_user_id
 * @property int $amount
 * @property string $currency
 * @property int $commission_rate_bps
 * @property PaymentStatus $status
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property string $idempotency_key
 * @property int $refunded_amount
 * @property string|null $failure_code
 * @property Carbon|null $captured_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read Appointment $appointment
 */
#[UsePolicy(PaymentPolicy::class)]
class Payment extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = [
        'appointment_id', 'patient_id', 'payer_user_id', 'amount', 'currency', 'commission_rate_bps',
        'status', 'provider', 'idempotency_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'commission_rate_bps' => 'integer',
            'refunded_amount' => 'integer',
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundableAmount(): int
    {
        return $this->status->isCaptured() ? $this->amount - $this->refunded_amount : 0;
    }
}
