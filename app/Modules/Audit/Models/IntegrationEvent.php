<?php

namespace App\Modules\Audit\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Metadata about a call to or from an external system (payment provider,
 * SMS gateway, e-prescribing service...). Never stores request/response bodies.
 *
 * @property string $id
 * @property string $integration
 * @property string $operation
 * @property string $direction outbound|inbound
 * @property string $status success|failure
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $external_reference
 * @property int|null $duration_ms
 * @property string|null $error_code
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class IntegrationEvent extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['duration_ms' => 'integer', 'created_at' => 'datetime'];
    }
}
