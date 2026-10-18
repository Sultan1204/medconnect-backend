<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $type
 * @property bool $signature_valid
 * @property array<string, mixed> $payload
 * @property string|null $payment_id
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 * @property Carbon $created_at
 */
class PaymentWebhookEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
