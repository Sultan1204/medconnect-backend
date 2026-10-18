<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Policies\DoctorPolicy;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Specialties\Models\Specialty;
use App\Support\Database\StoresUtcDates;
use Database\Factories\Doctors\DoctorFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $display_name_en
 * @property string|null $display_name_ar
 * @property string|null $title
 * @property string|null $gender
 * @property string|null $bio_en
 * @property string|null $bio_ar
 * @property int|null $years_of_experience
 * @property list<string> $languages
 * @property string|null $professional_rank
 * @property string|null $license_number
 * @property string|null $licensing_authority
 * @property Carbon|null $license_expires_on
 * @property VerificationStatus $verification_status
 * @property Carbon|null $verification_status_changed_at
 * @property Carbon|null $approved_at
 * @property int|null $video_fee_amount
 * @property int|null $chat_fee_amount
 * @property string $currency
 * @property bool $accepting_patients
 * @property string|null $rating_average
 * @property int $rating_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(DoctorFactory::class)]
#[UsePolicy(DoctorPolicy::class)]
class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    use StoresUtcDates;

    /** Changing any of these after approval sends the doctor back to review. */
    public const LICENSE_FIELDS = ['license_number', 'licensing_authority', 'license_expires_on'];

    protected $fillable = [
        'user_id', 'display_name_en', 'display_name_ar', 'title', 'gender', 'bio_en', 'bio_ar',
        'years_of_experience', 'languages', 'professional_rank',
        'license_number', 'licensing_authority', 'license_expires_on',
        'video_fee_amount', 'chat_fee_amount', 'accepting_patients',
    ];

    protected $attributes = [
        'verification_status' => 'pending',
        'languages' => '[]',
        'currency' => 'SAR',
        'accepting_patients' => true,
        'rating_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'license_expires_on' => 'date',
            'verification_status' => VerificationStatus::class,
            'verification_status_changed_at' => 'datetime',
            'approved_at' => 'datetime',
            'video_fee_amount' => 'integer',
            'chat_fee_amount' => 'integer',
            'accepting_patients' => 'boolean',
            'years_of_experience' => 'integer',
            'rating_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Specialty, $this> */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'doctor_specialties')->withPivot('is_primary')->withTimestamps();
    }

    /** @return HasMany<DoctorCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(DoctorCredential::class);
    }

    /** @return HasMany<DoctorVerification, $this> */
    public function verificationHistory(): HasMany
    {
        return $this->hasMany(DoctorVerification::class)->latest('created_at');
    }

    public function isLicenseValid(): bool
    {
        return $this->license_expires_on !== null && $this->license_expires_on->endOfDay()->isFuture();
    }

    /**
     * Eligible to appear in search and to provide live consultations.
     */
    public function isPubliclyListed(): bool
    {
        return $this->verification_status === VerificationStatus::Approved
            && $this->isLicenseValid()
            && $this->accepting_patients
            && $this->user?->status === UserStatus::Active
            && $this->offeredConsultationTypes() !== [];
    }

    /**
     * May the doctor conduct consultations (regardless of whether accepting new bookings)?
     */
    public function canPractice(): bool
    {
        return $this->verification_status === VerificationStatus::Approved
            && $this->isLicenseValid()
            && $this->user?->status === UserStatus::Active;
    }

    /**
     * @return list<string>
     */
    public function offeredConsultationTypes(): array
    {
        return array_values(array_filter([
            $this->video_fee_amount !== null ? 'video' : null,
            $this->chat_fee_amount !== null ? 'chat' : null,
        ]));
    }

    public function feeFor(string $consultationType): ?int
    {
        return match ($consultationType) {
            'video' => $this->video_fee_amount,
            'chat' => $this->chat_fee_amount,
            default => null,
        };
    }

    /**
     * Same rules as isPubliclyListed(), expressed as a query.
     *
     * @param  Builder<self>  $query
     */
    public function scopePubliclyListed(Builder $query): void
    {
        $query->where('verification_status', VerificationStatus::Approved)
            ->whereDate('license_expires_on', '>=', today())
            ->where('accepting_patients', true)
            ->where(fn (Builder $q) => $q->whereNotNull('video_fee_amount')->orWhereNotNull('chat_fee_amount'))
            ->whereHas('user', fn (Builder $q) => $q->where('status', UserStatus::Active));
    }
}
