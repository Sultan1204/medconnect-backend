<?php

namespace App\Modules\Pharmacies\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $pharmacy_id
 * @property string|null $branch_id null = every branch of the pharmacy
 * @property string $user_id
 * @property string $role manager|pharmacist|assistant
 * @property bool $is_active
 * @property-read Pharmacy $pharmacy
 * @property-read User $user
 */
class PharmacyStaff extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $table = 'pharmacy_staff';

    protected $fillable = ['pharmacy_id', 'branch_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Pharmacy, $this> */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function coversBranch(string $branchId): bool
    {
        return $this->branch_id === null || $this->branch_id === $branchId;
    }

    /** Only pharmacists may verify and dispense; assistants handle intake. */
    public function canDispense(): bool
    {
        return in_array($this->role, ['manager', 'pharmacist'], true);
    }
}
