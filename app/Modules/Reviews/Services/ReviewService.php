<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Models\ReviewReport;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Review lifecycle: submission, moderation, reporting, and keeping the
 * doctor's public rating consistent with the published reviews.
 */
class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The patient (or a verified guardian) reviews a completed consultation once.
     */
    public function submit(Consultation $consultation, User $actor, int $rating, ?string $comment): Review
    {
        $consultation->loadMissing('patient');

        if (Gate::forUser($actor)->denies('actFor', $consultation->patient)) {
            throw ApiException::forbidden();
        }

        if ($consultation->status !== ConsultationStatus::Completed) {
            throw ApiException::conflict('review_not_eligible', 'Only completed consultations can be reviewed.');
        }

        $window = (int) config('medconnect.reviews.window_days');
        if ($consultation->ended_at !== null && $consultation->ended_at->copy()->addDays($window)->isPast()) {
            throw ApiException::conflict('review_window_closed', "Reviews can be submitted within {$window} days of the consultation.");
        }

        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if (Review::where('consultation_id', $consultation->id)->exists()) {
            throw ApiException::conflict('review_already_submitted', 'This consultation has already been reviewed.');
        }

        try {
            return DB::transaction(function () use ($consultation, $actor, $rating, $comment) {
                $status = $this->initialStatus($comment);

                $review = Review::create([
                    'consultation_id' => $consultation->id,
                    'patient_id' => $consultation->patient_id,
                    'doctor_id' => $consultation->doctor_id,
                    'author_user_id' => $actor->id,
                    'rating' => $rating,
                    'comment' => $comment,
                    'status' => $status,
                    'published_at' => $status === ReviewStatus::Published ? now() : null,
                ]);

                // Field names and the outcome only - the text is not copied into the audit trail.
                $this->audit->record('reviews.submitted', $review, ['metadata' => ['status' => $status->value, 'has_comment' => $comment !== null]]);

                if ($status === ReviewStatus::Published) {
                    $this->recalculate($review->doctor_id);
                }

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submissions: the database constraint decides.
            throw ApiException::conflict('review_already_submitted', 'This consultation has already been reviewed.');
        }
    }

    /**
     * @param  'publish'|'hide'|'reject'  $decision
     */
    public function moderate(Review $review, User $moderator, string $decision, ?string $note): Review
    {
        return DB::transaction(function () use ($review, $moderator, $decision, $note) {
            /** @var Review $locked */
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            $target = match ($decision) {
                'publish' => ReviewStatus::Published,
                'hide' => ReviewStatus::Hidden,
                default => ReviewStatus::Rejected,
            };

            $allowed = match ($target) {
                ReviewStatus::Published => [ReviewStatus::Pending, ReviewStatus::Hidden],
                ReviewStatus::Hidden => [ReviewStatus::Published],
                default => [ReviewStatus::Pending, ReviewStatus::Hidden],
            };

            if (! in_array($locked->status, $allowed, true)) {
                throw ApiException::conflict('invalid_state_transition', "A {$locked->status->value} review cannot be moved to {$target->value}.");
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => $target,
                'published_at' => $target === ReviewStatus::Published ? ($locked->published_at ?? now()) : $locked->published_at,
                'moderated_by_user_id' => $moderator->id,
                'moderated_at' => now(),
                'moderation_note' => $note,
            ])->save();

            $this->audit->record("reviews.{$decision}", $locked, ['actor' => $moderator, 'metadata' => ['from' => $from->value, 'to' => $target->value]]);

            $this->recalculate($locked->doctor_id);

            return $locked;
        });
    }

    public function report(Review $review, User $reporter, string $reason, ?string $details): ReviewReport
    {
        if (ReviewReport::where('review_id', $review->id)->where('reporter_user_id', $reporter->id)->exists()) {
            throw ApiException::conflict('review_already_reported', 'You have already reported this review.');
        }

        try {
            return DB::transaction(function () use ($review, $reporter, $reason, $details) {
                $report = ReviewReport::create(['review_id' => $review->id, 'reporter_user_id' => $reporter->id, 'reason' => $reason, 'details' => $details]);
                $this->audit->record('reviews.reported', $report, ['actor' => $reporter, 'metadata' => ['review_id' => $review->id, 'reason' => $reason]]);

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('review_already_reported', 'You have already reported this review.');
        }
    }

    /**
     * Settle a report: dismiss it, or take the review down (which also closes
     * every other open report on the same review).
     *
     * @param  'dismiss'|'hide_review'  $action
     */
    public function resolveReport(ReviewReport $report, User $moderator, string $action, ?string $note): ReviewReport
    {
        return DB::transaction(function () use ($report, $moderator, $action, $note) {
            /** @var ReviewReport $locked */
            $locked = ReviewReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'open') {
                throw ApiException::conflict('report_already_handled', 'This report has already been handled.');
            }

            if ($action === 'hide_review') {
                $review = Review::findOrFail($locked->review_id);

                if ($review->status === ReviewStatus::Published) {
                    $this->moderate($review, $moderator, 'hide', $note);
                }

                ReviewReport::where('review_id', $locked->review_id)->where('status', 'open')->update([
                    'status' => 'actioned', 'handled_by_user_id' => $moderator->id, 'handled_at' => now(), 'handling_note' => $note,
                ]);
            } else {
                $locked->forceFill(['status' => 'dismissed', 'handled_by_user_id' => $moderator->id, 'handled_at' => now(), 'handling_note' => $note])->save();
            }

            $this->audit->record("reviews.report_{$action}", $locked, ['actor' => $moderator, 'metadata' => ['review_id' => $locked->review_id]]);

            return $locked->refresh();
        });
    }

    /**
     * Rebuilds the doctor's public rating from published reviews only. The doctor
     * row is locked so concurrent changes cannot overwrite each other with stale totals.
     */
    public function recalculate(string $doctorId): void
    {
        Doctor::whereKey($doctorId)->lockForUpdate()->first();

        $stats = Review::where('doctor_id', $doctorId)->where('status', ReviewStatus::Published->value)
            ->toBase()->selectRaw('count(*) as total, avg(rating) as average')->first();

        $count = (int) ($stats->total ?? 0);

        Doctor::whereKey($doctorId)->update([
            'rating_count' => $count,
            'rating_average' => $count > 0 ? round((float) $stats->average, 2) : null,
        ]);
    }

    /**
     * Published immediately only when moderation is switched off and the text
     * shows no sign of contact details or links; otherwise a moderator decides.
     */
    private function initialStatus(?string $comment): ReviewStatus
    {
        if (config('medconnect.reviews.require_moderation') || ($comment !== null && $this->looksIdentifying($comment))) {
            return ReviewStatus::Pending;
        }

        return ReviewStatus::Published;
    }

    private function looksIdentifying(string $comment): bool
    {
        return (bool) preg_match('/@|https?:|www\.|(?:\d[\s\-]?){7,}/iu', $comment);
    }
}
