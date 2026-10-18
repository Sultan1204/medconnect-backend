<?php

namespace App\Modules\MedicalRecords\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Models\ClinicalNote;
use App\Modules\MedicalRecords\Models\ClinicalNoteVersion;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

class ClinicalNoteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, string|null>  $content
     */
    public function saveDraft(Consultation $consultation, User $doctorUser, array $content): ClinicalNote
    {
        if (! in_array($consultation->status, [ConsultationStatus::Active, ConsultationStatus::Interrupted, ConsultationStatus::Completed], true)) {
            throw ApiException::conflict('consultation_not_started', 'Notes can be written once the consultation has started.');
        }

        return DB::transaction(function () use ($consultation, $doctorUser, $content) {
            $note = ClinicalNote::where('consultation_id', $consultation->id)->lockForUpdate()->first()
                ?? ClinicalNote::create([
                    'consultation_id' => $consultation->id,
                    'patient_id' => $consultation->patient_id,
                    'author_doctor_id' => $consultation->doctor_id,
                ]);

            if (! $note->isDraft()) {
                throw ApiException::conflict('note_finalized', 'This note is final. Submit an amendment instead.');
            }

            $note->draft_content = self::normalize($content);
            $note->save();

            $this->audit->record('clinical_notes.draft_saved', $note, $this->context($consultation, $doctorUser, ['sections' => array_keys(array_filter($note->draft_content))]));

            return $note;
        });
    }

    public function finalize(ClinicalNote $note, User $doctorUser): ClinicalNote
    {
        return DB::transaction(function () use ($note, $doctorUser) {
            /** @var ClinicalNote $locked */
            $locked = ClinicalNote::whereKey($note->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ApiException::conflict('note_finalized', 'This note is already final.');
            }

            $content = $locked->draft_content ?? [];
            if (blank($content['assessment'] ?? null) || blank($content['plan'] ?? null)) {
                throw ApiException::make('note_incomplete', 'An assessment and a plan are required before finalising.', 422, ['missing' => array_values(array_filter(['assessment', 'plan'], fn ($s) => blank($content[$s] ?? null)))]);
            }

            ClinicalNoteVersion::create([
                'clinical_note_id' => $locked->id,
                'version' => 1,
                'content' => $content,
                'author_user_id' => $doctorUser->id,
            ]);

            $locked->forceFill([
                'status' => 'final',
                'current_version' => 1,
                'draft_content' => null,
                'finalized_at' => now(),
            ])->save();

            $this->audit->record('clinical_notes.finalized', $locked, $this->context($locked->consultation, $doctorUser, ['version' => 1]));

            return $locked;
        });
    }

    /**
     * A correction never overwrites: it adds version n+1 attributed to its author.
     *
     * @param  array<string, string|null>  $content
     */
    public function amend(ClinicalNote $note, User $doctorUser, array $content, string $reason): ClinicalNote
    {
        return DB::transaction(function () use ($note, $doctorUser, $content, $reason) {
            /** @var ClinicalNote $locked */
            $locked = ClinicalNote::whereKey($note->id)->lockForUpdate()->with('latestVersion')->firstOrFail();

            if ($locked->isDraft()) {
                throw ApiException::conflict('note_not_final', 'Draft notes are edited directly; amendments apply to final notes.');
            }

            $merged = array_merge($locked->latestVersion->content ?? [], self::normalize($content));
            $version = $locked->current_version + 1;

            ClinicalNoteVersion::create([
                'clinical_note_id' => $locked->id,
                'version' => $version,
                'content' => $merged,
                'author_user_id' => $doctorUser->id,
                'change_reason' => $reason,
            ]);

            $locked->forceFill(['status' => 'amended', 'current_version' => $version])->save();

            $this->audit->record('clinical_notes.amended', $locked, $this->context($locked->consultation, $doctorUser, [
                'version' => $version,
                'sections_changed' => array_keys(self::normalize($content)),
            ]));

            return $locked;
        });
    }

    /**
     * Keeps only known sections; trims whitespace; empty strings become null.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, string|null>
     */
    public static function normalize(array $content): array
    {
        $out = [];

        foreach (ClinicalNote::SECTIONS as $section) {
            if (array_key_exists($section, $content)) {
                $value = is_string($content[$section]) ? trim($content[$section]) : null;
                $out[$section] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function context(Consultation $consultation, User $actor, array $metadata): array
    {
        return [
            'actor' => $actor,
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
            'metadata' => $metadata,
        ];
    }
}
