<?php

namespace App\Modules\MedicalRecords\Models;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Clinical documentation for one consultation.
 *
 * While `draft`, content is editable in `draft_content`. Finalising writes
 * version 1 to the immutable clinical_note_versions table; every later
 * correction is a new version with author and reason.
 *
 * @property string $id
 * @property string $consultation_id
 * @property string $patient_id
 * @property string $author_doctor_id
 * @property string $status draft|final|amended
 * @property int $current_version
 * @property array<string, string|null>|null $draft_content
 * @property Carbon|null $finalized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Doctor $author
 * @property-read Consultation $consultation
 * @property-read ClinicalNoteVersion|null $latestVersion
 */
class ClinicalNote extends Model
{
    use HasUuids;
    use StoresUtcDates;

    public const SECTIONS = ['subjective', 'objective', 'assessment', 'plan', 'follow_up', 'referral'];

    protected $fillable = ['consultation_id', 'patient_id', 'author_doctor_id'];

    protected $attributes = ['status' => 'draft', 'current_version' => 0];

    protected function casts(): array
    {
        return [
            'draft_content' => 'encrypted:array',
            'current_version' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'author_doctor_id')->withTrashed();
    }

    /** @return HasMany<ClinicalNoteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ClinicalNoteVersion::class)->orderBy('version');
    }

    /**
     * Not `ofMany()`: it adds a MAX(id) tie-breaker and PostgreSQL has no
     * max(uuid). (clinical_note_id, version) is unique, so ordering suffices;
     * eager loading keeps the first row per note.
     *
     * @return HasOne<ClinicalNoteVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ClinicalNoteVersion::class)->orderByDesc('version');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
