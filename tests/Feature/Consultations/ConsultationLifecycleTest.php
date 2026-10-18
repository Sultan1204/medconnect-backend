<?php

namespace Tests\Feature\Consultations;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Events\ConsultationCompleted;
use App\Modules\Consultations\Events\ConsultationStatusChanged;
use App\Modules\Consultations\Events\EmergencyEscalated;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Actions\CancelAppointment;
use App\Modules\Scheduling\Actions\TransitionAppointment;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class ConsultationLifecycleTest extends TestCase
{
    use ConsultationFixtures;

    /** @return array<string, bool> */
    private function noRedFlags(): array
    {
        return array_fill_keys(array_keys(config('medconnect.emergency.screening_questions')), false);
    }

    public function test_confirmed_appointments_get_a_consultation_and_paid_ones_only_after_payment(): void
    {
        $paid = Appointment::factory()->status(AppointmentStatus::AwaitingPayment)->create();
        event(new AppointmentBooked($paid));
        $this->assertNull(Consultation::where('appointment_id', $paid->id)->first());

        app(TransitionAppointment::class)->markPaid($paid);
        $this->assertSame(ConsultationStatus::Scheduled, Consultation::where('appointment_id', $paid->id)->firstOrFail()->status);
    }

    public function test_cancelling_the_appointment_cancels_the_consultation(): void
    {
        $consultation = $this->scheduledConsultation(startsInMinutes: 60 * 48);

        app(CancelAppointment::class)->handle($consultation->appointment, $consultation->patient->user, CancelAppointment::BY_PATIENT, null);

        $this->assertSame(ConsultationStatus::Cancelled, $consultation->fresh()->status);
    }

    public function test_doctor_starts_within_the_window_and_completes(): void
    {
        Event::fake([ConsultationStatusChanged::class, ConsultationCompleted::class]);
        $consultation = $this->scheduledConsultation(startsInMinutes: 30);
        Sanctum::actingAs($consultation->doctor->user, ['*']);

        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/start"), 409, 'consultation_not_open');

        $this->travel(21)->minutes();
        $this->postJson("/api/v1/consultations/{$consultation->id}/start")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.your_role', 'doctor')
            ->assertJsonPath('data.appointment.status', 'in_progress');
        Event::assertDispatched(ConsultationStatusChanged::class);

        $this->postJson("/api/v1/consultations/{$consultation->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(AppointmentStatus::Completed, $consultation->appointment->fresh()->status);
        Event::assertDispatched(ConsultationCompleted::class);
    }

    public function test_only_an_eligible_treating_doctor_can_start(): void
    {
        $consultation = $this->scheduledConsultation();

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/start"), 403, 'forbidden');

        Sanctum::actingAs(User::factory()->doctor()->create(), ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/start"), 403, 'forbidden');

        $consultation->doctor->forceFill(['verification_status' => VerificationStatus::Suspended])->save();
        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/start"), 403, 'doctor_not_eligible');
    }

    public function test_interrupted_consultations_can_be_resumed(): void
    {
        $consultation = $this->activeConsultation();
        Sanctum::actingAs($consultation->doctor->user, ['*']);

        $this->postJson("/api/v1/consultations/{$consultation->id}/interrupt", ['reason' => 'connection_failure'])
            ->assertOk()->assertJsonPath('data.status', 'interrupted');
        $this->postJson("/api/v1/consultations/{$consultation->id}/start")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'consultations.resumed', 'subject_id' => $consultation->id]);
    }

    public function test_time_limit_enforcement_interrupts_overdue_consultations(): void
    {
        $consultation = $this->activeConsultation();
        $this->travel(30 + 10 + 6)->minutes(); // slot (30) + grace (10) past the start in 5

        $this->artisan('consultations:enforce-time-limits')->assertSuccessful();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->fresh()->status);
    }

    public function test_only_participants_can_view_a_consultation(): void
    {
        $consultation = $this->scheduledConsultation();

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $this->getJson("/api/v1/consultations/{$consultation->id}")->assertOk()->assertJsonPath('data.your_role', 'patient');
        $this->getJson("/api/v1/appointments/{$consultation->appointment_id}/consultation")->assertOk();

        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::Admin, RoleName::SuperAdmin] as $role) {
            $this->actingAsRole($role);
            $this->assertErrorCode($this->getJson("/api/v1/consultations/{$consultation->id}"), 403, 'forbidden');
        }
    }

    public function test_patient_submits_intake_which_is_encrypted_and_visible_to_the_doctor(): void
    {
        $consultation = $this->scheduledConsultation();
        Sanctum::actingAs($consultation->patient->user, ['*']);

        $this->putJson("/api/v1/consultations/{$consultation->id}/intake", [
            'reason' => 'Persistent rash on forearm', 'details' => 'Two weeks, itchy', 'screening' => $this->noRedFlags(),
        ])->assertOk()->assertJsonPath('data.emergency', null);

        $this->assertStringNotContainsString('rash', (string) DB::table('consultations')->value('intake_reason'));

        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $this->getJson("/api/v1/consultations/{$consultation->id}")->assertJsonPath('data.intake.reason', 'Persistent rash on forearm');
    }

    public function test_every_screening_question_must_be_answered(): void
    {
        $consultation = $this->scheduledConsultation();
        Sanctum::actingAs($consultation->patient->user, ['*']);

        $this->putJson("/api/v1/consultations/{$consultation->id}/intake", ['reason' => 'x', 'screening' => ['chest_pain' => false]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['screening.breathing_difficulty']]]]);
    }

    public function test_red_flags_return_emergency_guidance_immediately_and_escalate(): void
    {
        Event::fake([EmergencyEscalated::class]);
        $consultation = $this->scheduledConsultation(startsInMinutes: 120);
        Sanctum::actingAs($consultation->patient->user, ['*']);

        $this->putJson("/api/v1/consultations/{$consultation->id}/intake", [
            'reason' => 'Chest discomfort', 'screening' => ['chest_pain' => true] + $this->noRedFlags(),
        ], ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonPath('data.emergency.is_emergency', true)
            ->assertJsonPath('data.emergency.red_flags', ['chest_pain'])
            ->assertJsonPath('data.emergency.numbers.0.number', '997')
            ->assertJsonPath('data.consultation.emergency_flagged', true);

        $this->assertDatabaseHas('consultation_escalations', ['consultation_id' => $consultation->id, 'source' => 'screening', 'type' => 'emergency']);
        Event::assertDispatched(EmergencyEscalated::class);
    }

    public function test_doctor_escalates_to_in_person_care(): void
    {
        Event::fake([EmergencyEscalated::class]);
        $consultation = $this->activeConsultation();
        Sanctum::actingAs($consultation->doctor->user, ['*']);

        $this->postJson("/api/v1/consultations/{$consultation->id}/escalate", ['type' => 'emergency', 'details' => 'Suspected anaphylaxis'])
            ->assertCreated()
            ->assertJsonPath('data.patient_guidance.is_emergency', true);

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/escalate", ['type' => 'emergency']), 403, 'forbidden');
    }

    public function test_emergency_information_is_public_and_localized(): void
    {
        $this->getJson('/api/v1/emergency-info', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.numbers.0.number', '997')
            ->assertJsonPath('data.screening_questions.0.key', 'chest_pain')
            ->assertJsonFragment(['question' => 'ألم أو ضغط شديد في الصدر']);
    }
}
