<?php

namespace App\Modules\MedicalRecords\Support;

use App\Modules\MedicalRecords\Models\MedicalRecord;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\MedicalRecords\Models\PatientMedication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Patient-level clinical entries that share one create/update workflow.
 */
enum RecordKind: string
{
    case Allergies = 'allergies';
    case Medications = 'medications';
    case History = 'history';

    /** @return class-string<PatientAllergy|PatientMedication|MedicalRecord> */
    public function model(): string
    {
        return match ($this) {
            self::Allergies => PatientAllergy::class,
            self::Medications => PatientMedication::class,
            self::History => MedicalRecord::class,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return match ($this) {
            self::Allergies => [
                'allergen' => [$required, 'string', 'max:200'],
                'reaction' => ['sometimes', 'nullable', 'string', 'max:500'],
                'severity' => ['sometimes', Rule::in(['mild', 'moderate', 'severe', 'unknown'])],
                'status' => ['sometimes', Rule::in(['active', 'inactive', 'entered_in_error'])],
            ],
            self::Medications => [
                'name' => [$required, 'string', 'max:200'],
                'dosage' => ['sometimes', 'nullable', 'string', 'max:100'],
                'frequency' => ['sometimes', 'nullable', 'string', 'max:100'],
                'started_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'ended_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
                'status' => ['sometimes', Rule::in(['active', 'stopped', 'entered_in_error'])],
            ],
            self::History => [
                'type' => [$required, Rule::in(MedicalRecord::TYPES)],
                'title' => [$required, 'string', 'max:200'],
                'details' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'onset_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'status' => ['sometimes', Rule::in(['active', 'resolved', 'entered_in_error'])],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Model $entry): array
    {
        $common = [
            'id' => $entry->getKey(),
            'status' => $entry->getAttribute('status'),
            'source' => $entry->getAttribute('source'),
            'consultation_id' => $entry->getAttribute('consultation_id'),
            'recorded_at' => $entry->getAttribute('created_at')?->toIso8601ZuluString(),
            'updated_at' => $entry->getAttribute('updated_at')?->toIso8601ZuluString(),
        ];

        return $common + match (true) {
            $entry instanceof PatientAllergy => ['allergen' => $entry->allergen, 'reaction' => $entry->reaction, 'severity' => $entry->severity],
            $entry instanceof PatientMedication => [
                'name' => $entry->name, 'dosage' => $entry->dosage, 'frequency' => $entry->frequency,
                'started_on' => $entry->started_on?->toDateString(), 'ended_on' => $entry->ended_on?->toDateString(),
            ],
            $entry instanceof MedicalRecord => [
                'type' => $entry->type, 'title' => $entry->title, 'details' => $entry->details,
                'onset_date' => $entry->onset_date?->toDateString(),
            ],
            default => [],
        };
    }
}
