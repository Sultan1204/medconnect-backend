<?php

namespace App\Modules\Reviews\Models;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Policies\ReviewPolicy;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A patient's rating of a completed consultation. Public only once published;
 * the author's identity is never exposed publicly.
 *
 * @property string $id
 * @property string $consultation_id
 * @property string $patient_id
 * @property string $doctor_id
 * @property string $author_user_id
 * @property int $rating
 * @property string|null $comment
 * @property ReviewStatus $status
 * @property Carbon|null $published_at
 * @property string|null $moderated_by_user_id
 * @property Carbon|null $moderated_at
 * @property string|null $moderation_note
 * @property Carbon $created_at
 * @property-read Doctor $doctor
 */
#[UsePolicy(ReviewPolicy::class)]
class Review extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['consultation_id', 'patient_id', 'doctor_id', 'author_user_id', 'rating', 'comment', 'status', 'published_at'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'published_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id')->withTrashed();
    }

    /** @return HasMany<ReviewReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }
}
