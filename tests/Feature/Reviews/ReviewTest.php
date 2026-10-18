<?php

namespace Tests\Feature\Reviews;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Models\ReviewReport;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use ConsultationFixtures;

    private Doctor $doctor;

    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = Doctor::factory()->approved()->create();
    }

    /** A completed consultation of $this->doctor (each gets its own time slot). */
    private function completed(?Patient $patient = null, ?Doctor $doctor = null): Consultation
    {
        $consultation = $this->scheduledConsultation('video', 5 + 40 * $this->slot++, $doctor ?? $this->doctor, $patient);
        $consultation->forceFill(['status' => ConsultationStatus::Completed, 'started_at' => now()->subMinutes(30), 'ended_at' => now()->subMinutes(5)])->save();

        return $consultation;
    }

    private function review(Consultation $consultation, array $body = ['rating' => 5, 'comment' => 'Very helpful and kind.'])
    {
        return $this->postJson("/api/v1/consultations/{$consultation->id}/review", $body);
    }

    private function asPatientOf(Consultation $consultation): User
    {
        Sanctum::actingAs($consultation->patient->user, ['*']);

        return $consultation->patient->user;
    }

    private function asModerator(): User
    {
        $admin = User::factory()->admin()->withMfa()->create();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function publishedReview(int $rating, ?Doctor $doctor = null): Review
    {
        $doctor ??= $this->doctor;
        $consultation = $this->completed(doctor: $doctor);

        return Review::create([
            'consultation_id' => $consultation->id, 'patient_id' => $consultation->patient_id, 'doctor_id' => $doctor->id,
            'author_user_id' => $consultation->patient->user_id, 'rating' => $rating, 'comment' => "Rated {$rating}",
            'status' => ReviewStatus::Published, 'published_at' => now(),
        ]);
    }

    // --- submission -------------------------------------------------------------

    public function test_a_patient_reviews_a_completed_consultation_and_it_awaits_moderation(): void
    {
        $consultation = $this->completed();
        $this->asPatientOf($consultation);

        $this->review($consultation)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.published_at', null);

        $this->assertNull($this->doctor->fresh()->rating_average);
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/reviews")->assertJsonCount(0, 'data');
    }

    public function test_without_moderation_a_clean_review_publishes_and_updates_the_doctor_rating(): void
    {
        config(['medconnect.reviews.require_moderation' => false]);
        $first = $this->completed();
        $second = $this->completed();

        $this->asPatientOf($first);
        $this->review($first, ['rating' => 5])->assertCreated()->assertJsonPath('data.status', 'published');
        $this->asPatientOf($second);
        $this->review($second, ['rating' => 4, 'comment' => 'Good'])->assertCreated();

        $doctor = $this->doctor->fresh();
        $this->assertSame(2, $doctor->rating_count);
        $this->assertSame('4.50', $doctor->rating_average);
    }

    public function test_reviews_that_look_like_they_contain_contact_details_are_always_held_back(): void
    {
        config(['medconnect.reviews.require_moderation' => false]);

        foreach (['Call me on 0500 123 456', 'mail me at a@b.com', 'see https://example.com', 'www.clinic.sa is better'] as $comment) {
            $consultation = $this->completed();
            $this->asPatientOf($consultation);
            $this->review($consultation, ['rating' => 1, 'comment' => $comment])->assertCreated()->assertJsonPath('data.status', 'pending');
        }

        $this->assertSame(0, $this->doctor->fresh()->rating_count);
    }

    public function test_only_completed_consultations_can_be_reviewed(): void
    {
        $consultation = $this->completed();
        $consultation->forceFill(['status' => ConsultationStatus::Active])->save();
        $this->asPatientOf($consultation);

        $this->assertErrorCode($this->review($consultation), 409, 'review_not_eligible');
        $this->assertSame(0, Review::count());
    }

    public function test_the_review_window_closes(): void
    {
        $consultation = $this->completed();
        $consultation->forceFill(['ended_at' => now()->subDays(31)])->save();
        $this->asPatientOf($consultation);

        $this->assertErrorCode($this->review($consultation), 409, 'review_window_closed');
    }

    public function test_a_consultation_can_only_be_reviewed_once(): void
    {
        $consultation = $this->completed();
        $this->asPatientOf($consultation);

        $this->review($consultation)->assertCreated();
        $this->assertErrorCode($this->review($consultation, ['rating' => 1]), 409, 'review_already_submitted');
        $this->assertSame(1, Review::count());
    }

    public function test_the_database_itself_rejects_a_second_review_for_the_same_consultation(): void
    {
        $consultation = $this->completed();
        $row = ['consultation_id' => $consultation->id, 'patient_id' => $consultation->patient_id, 'doctor_id' => $this->doctor->id, 'author_user_id' => $consultation->patient->user_id, 'rating' => 3];
        Review::create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        Review::create($row);
    }

    public function test_only_the_patient_of_that_consultation_can_review_it(): void
    {
        $consultation = $this->completed();

        $this->actingAsRole(RoleName::Patient);
        $this->assertErrorCode($this->review($consultation), 403, 'forbidden');

        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $this->review($consultation)->assertForbidden(); // doctors cannot review themselves

        $this->assertSame(0, Review::count());
    }

    public function test_a_guardian_reviews_on_behalf_of_a_dependant(): void
    {
        $guardian = User::factory()->patient()->create();
        $child = Patient::factory()->dependentOf($guardian)->create();
        $appointment = Appointment::factory()->for($this->doctor, 'doctor')->for($child, 'patient')->at(now()->addMinutes(5))
            ->create(['booked_by_user_id' => $guardian->id, 'status' => AppointmentStatus::Confirmed]);
        $consultation = app(ConsultationLifecycle::class)->ensureFor($appointment);
        $consultation->forceFill(['status' => ConsultationStatus::Completed, 'ended_at' => now()->subMinutes(5)])->save();
        Sanctum::actingAs($guardian, ['*']);

        $this->review($consultation)->assertCreated();
        $this->assertSame($guardian->id, Review::firstOrFail()->author_user_id);
    }

    public function test_input_is_validated(): void
    {
        $consultation = $this->completed();
        $this->asPatientOf($consultation);

        foreach ([['rating' => 0], ['rating' => 6], ['rating' => 'five'], [], ['rating' => 5, 'comment' => str_repeat('x', 1001)]] as $body) {
            $this->review($consultation, $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_the_audit_trail_records_the_event_but_not_the_review_text(): void
    {
        $consultation = $this->completed();
        $this->asPatientOf($consultation);
        $this->review($consultation, ['rating' => 2, 'comment' => 'ZZZ-private-words']);

        $entry = AuditLog::where('action', 'reviews.submitted')->firstOrFail();
        $this->assertSame(['status' => 'pending', 'has_comment' => true], $entry->metadata);
        $this->assertStringNotContainsString('ZZZ-private-words', json_encode($entry->toArray()));
    }

    // --- public reading ---------------------------------------------------------

    public function test_public_reviews_are_anonymous_and_only_published_ones_appear(): void
    {
        $published = $this->publishedReview(5);
        $pending = $this->completed();
        Review::create(['consultation_id' => $pending->id, 'patient_id' => $pending->patient_id, 'doctor_id' => $this->doctor->id, 'author_user_id' => $pending->patient->user_id, 'rating' => 1, 'comment' => 'pending one']);

        $response = $this->getJson("/api/v1/doctors/{$this->doctor->id}/reviews")->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(['id', 'rating', 'comment', 'published_at'], array_keys($response->json('data.0')));
        $this->assertSame($published->id, $response->json('data.0.id'));
        $this->assertStringNotContainsString($published->author_user_id, $response->getContent());
        $this->assertStringNotContainsString($published->consultation_id, $response->getContent());
    }

    public function test_unlisted_doctors_have_no_public_reviews(): void
    {
        $hidden = Doctor::factory()->status(VerificationStatus::Suspended)->create();

        $this->getJson("/api/v1/doctors/{$hidden->id}/reviews")->assertNotFound();
        $this->getJson("/api/v1/doctors/{$hidden->id}/reviews/summary")->assertNotFound();
    }

    public function test_rating_summary_includes_the_distribution(): void
    {
        $admin = $this->asModerator();
        $reviews = [$this->publishedReview(5), $this->publishedReview(5), $this->publishedReview(3)];
        app(ReviewService::class)->recalculate($this->doctor->id);

        $this->getJson("/api/v1/doctors/{$this->doctor->id}/reviews/summary")->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.average', 4.33)
            ->assertJsonPath('data.distribution.5', 2)
            ->assertJsonPath('data.distribution.3', 1)
            ->assertJsonPath('data.distribution.1', 0);
        $this->assertCount(3, $reviews);
        $this->assertNotNull($admin);
    }

    public function test_doctors_see_their_published_reviews_and_summary(): void
    {
        $this->publishedReview(4);
        app(ReviewService::class)->recalculate($this->doctor->id);
        Sanctum::actingAs($this->doctor->user, ['*']);

        $this->getJson('/api/v1/doctors/me/reviews')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 4);

        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/doctors/me/reviews')->assertForbidden();
    }

    public function test_patients_see_all_of_their_own_reviews_with_status(): void
    {
        $consultation = $this->completed();
        $this->asPatientOf($consultation);
        $this->review($consultation)->assertCreated();

        $this->getJson('/api/v1/reviews/mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending');

        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/reviews/mine')->assertJsonCount(0, 'data');
    }

    // --- reporting --------------------------------------------------------------

    public function test_a_doctor_can_report_a_published_review_once(): void
    {
        $review = $this->publishedReview(1);
        Sanctum::actingAs($this->doctor->user, ['*']);

        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'inaccurate', 'details' => 'Never happened'])->assertCreated();
        $this->assertErrorCode($this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'abusive']), 409, 'review_already_reported');
        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'because'])->assertUnprocessable();
        $this->assertSame(1, ReviewReport::count());
    }

    public function test_authors_cannot_report_their_own_review_and_unpublished_reviews_cannot_be_reported(): void
    {
        $review = $this->publishedReview(5);
        Sanctum::actingAs($review->author, ['*']);
        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertForbidden();

        $review->forceFill(['status' => ReviewStatus::Hidden])->save();
        Sanctum::actingAs($this->doctor->user, ['*']);
        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertForbidden();
    }

    public function test_reporting_requires_authentication(): void
    {
        $review = $this->publishedReview(5);
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertUnauthorized();
    }

    // --- moderation -------------------------------------------------------------

    private function pendingReview(int $rating = 4): Review
    {
        $consultation = $this->completed();

        return Review::create(['consultation_id' => $consultation->id, 'patient_id' => $consultation->patient_id, 'doctor_id' => $this->doctor->id, 'author_user_id' => $consultation->patient->user_id, 'rating' => $rating, 'comment' => 'ok']);
    }

    public function test_moderation_endpoints_need_the_moderation_permission(): void
    {
        $review = $this->pendingReview();

        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::PharmacyStaff] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/admin/reviews')->assertForbidden();
            $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'publish'])->assertForbidden();
        }

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_publishing_makes_a_review_public_and_updates_the_rating(): void
    {
        $review = $this->pendingReview(4);
        $admin = $this->asModerator();

        $this->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'publish'])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertSame(1, $this->doctor->fresh()->rating_count);
        $this->assertSame('4.00', $this->doctor->fresh()->rating_average);
        $this->assertSame($admin->id, $review->fresh()->moderated_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reviews.publish', 'subject_id' => $review->id, 'actor_id' => $admin->id]);
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/reviews")->assertJsonCount(1, 'data');
    }

    public function test_hiding_takes_a_review_down_and_recalculates_but_needs_a_note(): void
    {
        $keep = $this->publishedReview(5);
        $remove = $this->publishedReview(1);
        app(ReviewService::class)->recalculate($this->doctor->id);
        $this->assertSame('3.00', $this->doctor->fresh()->rating_average);
        $this->asModerator();

        $this->postJson("/api/v1/admin/reviews/{$remove->id}/moderate", ['decision' => 'hide'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/reviews/{$remove->id}/moderate", ['decision' => 'hide', 'note' => 'Abusive language'])->assertOk()
            ->assertJsonPath('data.status', 'hidden')->assertJsonPath('data.moderation_note', 'Abusive language');

        $this->assertSame(1, $this->doctor->fresh()->rating_count);
        $this->assertSame('5.00', $this->doctor->fresh()->rating_average);
        $this->assertSame(ReviewStatus::Published, $keep->fresh()->status);
    }

    public function test_the_last_published_review_being_removed_clears_the_rating(): void
    {
        $only = $this->publishedReview(2);
        app(ReviewService::class)->recalculate($this->doctor->id);
        $this->asModerator();

        $this->postJson("/api/v1/admin/reviews/{$only->id}/moderate", ['decision' => 'hide', 'note' => 'x'])->assertOk();

        $this->assertSame(0, $this->doctor->fresh()->rating_count);
        $this->assertNull($this->doctor->fresh()->rating_average);
    }

    public function test_invalid_moderation_transitions_are_rejected(): void
    {
        $published = $this->publishedReview(3);
        $pending = $this->pendingReview();
        $this->asModerator();

        // A published review must be hidden before it can be rejected; pending ones cannot be "hidden".
        $this->assertErrorCode($this->postJson("/api/v1/admin/reviews/{$published->id}/moderate", ['decision' => 'reject', 'note' => 'x']), 409, 'invalid_state_transition');
        $this->assertErrorCode($this->postJson("/api/v1/admin/reviews/{$pending->id}/moderate", ['decision' => 'hide', 'note' => 'x']), 409, 'invalid_state_transition');
        $this->assertErrorCode($this->postJson("/api/v1/admin/reviews/{$published->id}/moderate", ['decision' => 'publish']), 409, 'invalid_state_transition');

        $this->postJson("/api/v1/admin/reviews/{$pending->id}/moderate", ['decision' => 'reject', 'note' => 'Spam'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertErrorCode($this->postJson("/api/v1/admin/reviews/{$pending->id}/moderate", ['decision' => 'publish']), 409, 'invalid_state_transition');
    }

    public function test_a_hidden_review_can_be_restored_or_rejected(): void
    {
        $review = $this->publishedReview(5);
        $this->asModerator();

        $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'hide', 'note' => 'Under review'])->assertOk();
        $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'publish'])->assertOk();
        $this->assertSame(1, $this->doctor->fresh()->rating_count);

        $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'hide', 'note' => 'Again'])->assertOk();
        $this->postJson("/api/v1/admin/reviews/{$review->id}/moderate", ['decision' => 'reject', 'note' => 'Final'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_resolving_a_report_by_hiding_closes_every_open_report_on_the_review(): void
    {
        $review = $this->publishedReview(1);
        $doctorReport = ReviewReport::create(['review_id' => $review->id, 'reporter_user_id' => $this->doctor->user_id, 'reason' => 'abusive']);
        $otherReport = ReviewReport::create(['review_id' => $review->id, 'reporter_user_id' => User::factory()->patient()->create()->id, 'reason' => 'spam']);
        app(ReviewService::class)->recalculate($this->doctor->id);
        $this->asModerator();

        $this->getJson('/api/v1/admin/review-reports')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/reviews?reported=1&status=published')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reports_count', 2);

        $this->postJson("/api/v1/admin/review-reports/{$doctorReport->id}/resolve", ['action' => 'hide_review', 'note' => 'Confirmed abusive'])->assertOk()
            ->assertJsonPath('data.status', 'actioned');

        $this->assertSame(ReviewStatus::Hidden, $review->fresh()->status);
        $this->assertSame('actioned', $otherReport->fresh()->status);
        $this->assertNull($this->doctor->fresh()->rating_average);
        $this->assertErrorCode($this->postJson("/api/v1/admin/review-reports/{$doctorReport->id}/resolve", ['action' => 'dismiss', 'note' => 'late']), 409, 'report_already_handled');
    }

    public function test_dismissing_a_report_leaves_the_review_published(): void
    {
        $review = $this->publishedReview(2);
        $report = ReviewReport::create(['review_id' => $review->id, 'reporter_user_id' => $this->doctor->user_id, 'reason' => 'inaccurate']);
        $this->asModerator();

        $this->postJson("/api/v1/admin/review-reports/{$report->id}/resolve", ['action' => 'dismiss', 'note' => 'Within guidelines'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->postJson("/api/v1/admin/review-reports/{$report->id}/resolve", ['action' => 'dismiss'])->assertUnprocessable(); // note is required

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
    }
}
