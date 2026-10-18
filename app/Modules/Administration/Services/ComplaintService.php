<?php

namespace App\Modules\Administration\Services;

use App\Modules\Administration\Enums\ComplaintStatus;
use App\Modules\Administration\Models\Complaint;
use App\Modules\Administration\Models\ComplaintEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Complaint intake and handling. The complainant must have a real
 * relationship to the thing they complain about; handlers' internal notes
 * never reach the complainant.
 */
class ComplaintService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{subject_type: string, subject_id?: string|null, category: string, description: string}  $data
     */
    public function submit(User $actor, array $data): Complaint
    {
        $patientId = $this->authorizeSubject($actor, $data['subject_type'], $data['subject_id'] ?? null);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $data, $patientId) {
                    $complaint = Complaint::create([
                        'reference' => 'CMP-'.strtoupper(bin2hex(random_bytes(4))),
                        'complainant_user_id' => $actor->id,
                        'patient_id' => $patientId,
                        'subject_type' => $data['subject_type'],
                        'subject_id' => $data['subject_type'] === 'platform' ? null : $data['subject_id'],
                        'category' => $data['category'],
                        'description' => $data['description'],
                    ]);

                    $this->event($complaint, 'submitted', $actor, visibility: 'public');
                    $this->audit->record('complaints.submitted', $complaint, [
                        'patient_id' => $patientId,
                        'metadata' => ['category' => $complaint->category, 'subject_type' => $complaint->subject_type],
                    ]);

                    return $complaint;
                });
            } catch (UniqueConstraintViolationException) {
                // Reference collision (1 in 4 billion): try another.
            }
        }

        throw ApiException::make('complaint_reference_failed', 'The complaint could not be recorded. Please try again.', 503);
    }

    /**
     * Handler action: assign, change priority/status, add an internal note.
     *
     * @param  array{status?: string, priority?: string, assigned_to_user_id?: string|null, note?: string|null, resolution?: string|null}  $changes
     */
    public function handle(Complaint $complaint, User $handler, array $changes): Complaint
    {
        return DB::transaction(function () use ($complaint, $handler, $changes) {
            /** @var Complaint $locked */
            $locked = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isClosed()) {
                throw ApiException::conflict('complaint_closed', 'This complaint has been closed.');
            }

            $from = $locked->status;
            $target = isset($changes['status']) ? ComplaintStatus::from($changes['status']) : $from;

            if (array_key_exists('assigned_to_user_id', $changes) && $changes['assigned_to_user_id'] !== null && $changes['assigned_to_user_id'] !== $locked->assigned_to_user_id) {
                $assignee = User::find($changes['assigned_to_user_id']);

                if ($assignee === null || ! $assignee->isActive() || ! $assignee->can(Permission::ComplaintsManage->value)) {
                    throw ApiException::make('invalid_assignee', 'The assignee must be an active administrator who can handle complaints.');
                }

                $locked->assigned_to_user_id = $assignee->id;
                $this->event($locked, 'assigned', $handler, visibility: 'internal');

                // Picking a complaint up starts the review.
                if ($target === ComplaintStatus::Open) {
                    $target = ComplaintStatus::UnderReview;
                }
            }

            if (isset($changes['priority']) && $changes['priority'] !== $locked->priority) {
                $this->event($locked, 'priority_changed', $handler, note: "{$locked->priority} -> {$changes['priority']}", visibility: 'internal');
                $locked->priority = $changes['priority'];
            }

            if ($target !== $from) {
                if ($target === ComplaintStatus::Open) {
                    throw ApiException::conflict('invalid_state_transition', 'A complaint cannot be moved back to open.');
                }

                if ($target->isClosed()) {
                    if (blank($changes['resolution'] ?? null)) {
                        throw ApiException::make('resolution_required', 'A resolution explaining the outcome is required to close a complaint.');
                    }

                    $locked->resolution = $changes['resolution'];
                    $locked->resolved_by_user_id = $handler->id;
                    $locked->resolved_at = now();
                }

                $locked->status = $target;
                $this->event($locked, 'status_changed', $handler, fromStatus: $from->value, toStatus: $target->value, visibility: 'public');
            }

            if (filled($changes['note'] ?? null)) {
                $this->event($locked, 'note', $handler, note: $changes['note'], visibility: 'internal');
            }

            $locked->save();

            $this->audit->record('complaints.handled', $locked, [
                'actor' => $handler,
                'patient_id' => $locked->patient_id,
                'changes' => ['fields' => array_values(array_keys(array_diff_key($changes, ['note' => 1, 'resolution' => 1]))), 'status' => $locked->status->value],
            ]);

            if ($target !== $from) {
                $complainant = User::find($locked->complainant_user_id);

                if ($complainant !== null) {
                    $this->notifications->notify($complainant, NotificationType::ComplaintUpdated, ['reference' => $locked->reference], "complaint:{$locked->id}:{$target->value}");
                }
            }

            return $locked->refresh();
        });
    }

    /**
     * The complainant must be a participant in what they complain about.
     * Missing and inaccessible subjects are indistinguishable on purpose.
     *
     * @return string|null the patient the complaint concerns, if any
     */
    private function authorizeSubject(User $actor, string $type, ?string $subjectId): ?string
    {
        if ($type === 'platform') {
            return null;
        }

        $invalid = fn () => ApiException::make('invalid_subject', 'The complaint subject was not found or is not yours to complain about.');

        if ($subjectId === null) {
            throw $invalid();
        }

        $gate = Gate::forUser($actor);

        switch ($type) {
            case 'consultation':
                $consultation = Consultation::with(['patient', 'doctor'])->find($subjectId);
                $participant = $consultation !== null && (
                    $consultation->doctor->user_id === $actor->id || $gate->allows('viewClinical', $consultation->patient)
                );

                return $participant ? $consultation->patient_id : throw $invalid();

            case 'dispense_request':
                $request = DispenseRequest::with('patient')->find($subjectId);

                return $request !== null && ($request->requested_by_user_id === $actor->id || $gate->allows('viewClinical', $request->patient))
                    ? $request->patient_id
                    : throw $invalid();

            default: // payment
                $payment = Payment::find($subjectId);

                return $payment !== null && $payment->payer_user_id === $actor->id ? $payment->patient_id : throw $invalid();
        }
    }

    private function event(Complaint $complaint, string $type, ?User $actor, ?string $fromStatus = null, ?string $toStatus = null, ?string $note = null, string $visibility = 'internal'): void
    {
        ComplaintEvent::create([
            'complaint_id' => $complaint->id,
            'actor_user_id' => $actor?->id,
            'type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'visibility' => $visibility,
        ]);
    }
}
