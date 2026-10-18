<?php

namespace App\Modules\Administration\Models;

use App\Modules\Administration\Enums\ComplaintStatus;
use App\Modules\Administration\Policies\ComplaintPolicy;
use App\Modules\Identity\Models\User;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A complaint about a consultation, pharmacy request, payment or the platform.
 * The description and resolution may contain health information: encrypted.
 *
 * @property string $id
 * @property string $reference
 * @property string $complainant_user_id
 * @property string|null $patient_id
 * @property string $subject_type consultation|dispense_request|payment|platform
 * @property string|null $subject_id
 * @property string $category
 * @property string $description
 * @property ComplaintStatus $status
 * @property string $priority
 * @property string|null $assigned_to_user_id
 * @property string|null $resolution
 * @property string|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $complainant
 */
#[UsePolicy(ComplaintPolicy::class)]
class Complaint extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const SUBJECT_TYPES = ['consultation', 'dispense_request', 'payment', 'platform'];

    public const CATEGORIES = ['service_quality', 'conduct', 'billing', 'privacy', 'technical', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = ['reference', 'complainant_user_id', 'patient_id', 'subject_type', 'subject_id', 'category', 'description'];

    protected $attributes = ['status' => 'open', 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'status' => ComplaintStatus::class,
            'description' => 'encrypted',
            'resolution' => 'encrypted',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function complainant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complainant_user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id')->withTrashed();
    }

    /** @return HasMany<ComplaintEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ComplaintEvent::class)->orderBy('created_at')->orderBy('id');
    }
}
