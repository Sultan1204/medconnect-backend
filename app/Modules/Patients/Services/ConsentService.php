<?php

namespace App\Modules\Patients\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ConsentService
{
    public function __construct(
        private readonly Request $request,
        private readonly AuditLogger $audit,
    ) {}

    public function record(Patient $patient, string $type, bool $granted, User $recordedBy): PatientConsent
    {
        $consent = PatientConsent::create([
            'patient_id' => $patient->id,
            'consent_type' => $type,
            'version' => self::currentVersion($type),
            'granted' => $granted,
            'recorded_by_user_id' => $recordedBy->id,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500) ?: null,
        ]);

        $this->audit->record($granted ? 'patients.consent_granted' : 'patients.consent_withdrawn', $consent, [
            'actor' => $recordedBy,
            'patient_id' => $patient->id,
            'metadata' => ['type' => $type, 'version' => $consent->version],
        ]);

        return $consent;
    }

    /**
     * Latest decision per consent type.
     *
     * @return Collection<string, PatientConsent>
     */
    public function latest(Patient $patient): Collection
    {
        return PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('consent_type')
            ->keyBy('consent_type');
    }

    /**
     * Consent types still needed (missing, withdrawn or outdated version) before booking.
     *
     * @return list<string>
     */
    public function missingForBooking(Patient $patient): array
    {
        $latest = $this->latest($patient);

        return array_values(array_filter(
            config('medconnect.consents.required_for_booking'),
            function (string $type) use ($latest) {
                $consent = $latest->get($type);

                return $consent === null || ! $consent->granted || $consent->version !== self::currentVersion($type);
            },
        ));
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(config('medconnect.consents.versions'));
    }

    public static function currentVersion(string $type): string
    {
        return (string) config("medconnect.consents.versions.{$type}");
    }
}
