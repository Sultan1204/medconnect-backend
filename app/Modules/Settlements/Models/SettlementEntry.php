<?php

namespace App\Modules\Settlements\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only ledger line (DB trigger enforced). Signed amount in halalas:
 * positive = owed to the account holder.
 *
 * @property string $id
 * @property string $account doctor_payable|platform_revenue
 * @property string|null $doctor_id
 * @property string $type
 * @property int $amount
 * @property string $currency
 * @property string|null $appointment_id
 * @property string|null $payment_id
 * @property string|null $refund_id
 * @property string|null $payout_id
 * @property string|null $description
 * @property Carbon $created_at
 */
class SettlementEntry extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const UPDATED_AT = null;

    public const DOCTOR = 'doctor_payable';

    public const PLATFORM = 'platform_revenue';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }
}
