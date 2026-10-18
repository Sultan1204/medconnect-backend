<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Contracts\ProfessionalVerifier;
use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Enums\VerificationStatus as Status;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorVerification;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Professional verification state machine.
 *
 *   pending ──submit──► under_review ──approve──► approved ──suspend──► suspended
 *      ▲                    │   ▲                    │   │                 │
 *      │                 reject │ licence changed ───┘   expire        reinstate
 *      │                    ▼   │                        ▼                 │
 *   (resubmit) ◄──────── rejected / expired ◄────────────┘   approved / under_review
 *
 * Every transition writes a DoctorVerification row and an audit entry.
 */
class DoctorVerificationService
{
    public function __construct(
        private readonly ProfessionalVerifier $verifier,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(Doctor $doctor, User $actor): Doctor
    {
        if (! $doctor->verification_status->canSubmit()) {
            throw ApiException::conflict('invalid_state_transition', 'Your profile cannot be submitted for review in its current state.');
        }

        $missing = $this->missingRequirements($doctor);
        if ($missing !== []) {
            throw ApiException::make('verification_requirements_missing', 'Complete your profile before submitting it for verification.', 422, ['missing' => $missing]);
        }

        $result = $this->verifier->verify($doctor);

        return $this->transition($doctor, Status::UnderReview, 'submitted', $actor, null, [
            'external_check' => $result->toArray(),
        ], $this->verifier->source());
    }

    /**
     * @param  list<array{id: string, status: string, note?: string|null}>  $credentialReviews
     */
    public function approve(Doctor $doctor, User $reviewer, ?string $note, array $credentialReviews = []): Doctor
    {
        $this->assertStatus($doctor, [Status::UnderReview]);

        return DB::transaction(function () use ($doctor, $reviewer, $note, $credentialReviews) {
            $this->reviewCredentials($doctor, $reviewer, $credentialReviews);

            if (! $doctor->isLicenseValid()) {
                throw ApiException::make('license_expired', 'The professional licence has expired.');
            }

            $hasAcceptedLicense = $doctor->credentials()
                ->where('type', CredentialType::ProfessionalLicense)
                ->where('review_status', 'accepted')
                ->exists();

            if (! $hasAcceptedLicense) {
                throw ApiException::make('license_document_not_accepted', 'Accept at least one professional licence document before approving.');
            }

            $doctor->approved_at = now();

            return $this->transition($doctor, Status::Approved, 'approved', $reviewer, $note, [
                'credentials_reviewed' => array_column($credentialReviews, 'id'),
            ]);
        });
    }

    /**
     * @param  list<array{id: string, status: string, note?: string|null}>  $credentialReviews
     */
    public function reject(Doctor $doctor, User $reviewer, string $reason, array $credentialReviews = []): Doctor
    {
        $this->assertStatus($doctor, [Status::UnderReview]);

        return DB::transaction(function () use ($doctor, $reviewer, $reason, $credentialReviews) {
            $this->reviewCredentials($doctor, $reviewer, $credentialReviews);

            return $this->transition($doctor, Status::Rejected, 'rejected', $reviewer, $reason, [
                'credentials_reviewed' => array_column($credentialReviews, 'id'),
            ]);
        });
    }

    public function suspend(Doctor $doctor, User $admin, string $reason): Doctor
    {
        $this->assertStatus($doctor, [Status::Approved, Status::UnderReview]);

        return $this->transition($doctor, Status::Suspended, 'suspended', $admin, $reason);
    }

    public function reinstate(Doctor $doctor, User $admin, string $reason): Doctor
    {
        $this->assertStatus($doctor, [Status::Suspended]);

        // A licence that lapsed during the suspension must be reviewed again.
        $target = $doctor->isLicenseValid() ? Status::Approved : Status::UnderReview;

        return $this->transition($doctor, $target, 'reinstated', $admin, $reason);
    }

    /**
     * Called after a doctor edits licence details. Approved/expired doctors
     * return to review; nothing changes for doctors not yet approved.
     */
    public function licenseChanged(Doctor $doctor, User $actor): Doctor
    {
        if (! in_array($doctor->verification_status, [Status::Approved, Status::Expired], true)) {
            return $doctor;
        }

        return $this->transition($doctor, Status::UnderReview, 'license_changed', $actor, 'Licence details were changed and require re-verification.');
    }

    /**
     * Scheduled: approved doctors whose licence has lapsed stop being listed.
     */
    public function expireLapsedLicenses(): int
    {
        $count = 0;

        Doctor::query()
            ->where('verification_status', Status::Approved)
            ->whereDate('license_expires_on', '<', today())
            ->each(function (Doctor $doctor) use (&$count) {
                $this->transition($doctor, Status::Expired, 'expired', null, 'Professional licence expired.', [], 'system');
                $count++;
            });

        return $count;
    }

    /**
     * @return list<string>
     */
    public function missingRequirements(Doctor $doctor): array
    {
        $missing = [];

        foreach (['license_number', 'licensing_authority', 'license_expires_on'] as $field) {
            if (blank($doctor->{$field})) {
                $missing[] = $field;
            }
        }

        if ($doctor->license_expires_on !== null && ! $doctor->isLicenseValid()) {
            $missing[] = 'license_expires_on_in_future';
        }

        if (! $doctor->specialties()->exists()) {
            $missing[] = 'specialties';
        }

        if ($doctor->offeredConsultationTypes() === []) {
            $missing[] = 'consultation_fees';
        }

        $hasLicenseDocument = $doctor->credentials()
            ->where('type', CredentialType::ProfessionalLicense)
            ->where('review_status', '!=', 'rejected')
            ->exists();

        if (! $hasLicenseDocument) {
            $missing[] = 'professional_license_document';
        }

        return $missing;
    }

    /**
     * @param  list<Status>  $allowed
     */
    private function assertStatus(Doctor $doctor, array $allowed): void
    {
        if (! in_array($doctor->verification_status, $allowed, true)) {
            throw ApiException::conflict('invalid_state_transition', "This action is not allowed while the doctor is {$doctor->verification_status->value}.");
        }
    }

    /**
     * @param  list<array{id: string, status: string, note?: string|null}>  $reviews
     */
    private function reviewCredentials(Doctor $doctor, User $reviewer, array $reviews): void
    {
        foreach ($reviews as $review) {
            /** @var DoctorCredential $credential */
            $credential = $doctor->credentials()->whereKey($review['id'])->firstOrFail();
            $credential->forceFill([
                'review_status' => $review['status'],
                'review_note' => $review['note'] ?? null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function transition(Doctor $doctor, Status $to, string $action, ?User $actor, ?string $reason, array $evidence = [], string $source = 'manual'): Doctor
    {
        return DB::transaction(function () use ($doctor, $to, $action, $actor, $reason, $evidence, $source) {
            $from = $doctor->verification_status;

            $doctor->forceFill([
                'verification_status' => $to,
                'verification_status_changed_at' => now(),
            ])->save();

            DoctorVerification::create([
                'doctor_id' => $doctor->id,
                'action' => $action,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'evidence' => $evidence ?: null,
                'source' => $source,
            ]);

            $this->audit->record('doctors.verification.'.$action, $doctor, [
                'actor' => $actor,
                'changes' => ['verification_status' => [$from->value, $to->value]],
            ]);

            return $doctor;
        });
    }
}
