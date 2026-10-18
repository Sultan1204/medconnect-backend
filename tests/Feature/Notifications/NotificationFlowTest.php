<?php

namespace Tests\Feature\Notifications;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Events\SuspiciousActivityDetected;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Contracts\PushProvider;
use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Push\FakePushProvider;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PrescriptionFixtures;
use Tests\TestCase;

/**
 * Domain events become notifications - and nothing sensitive leaves the platform.
 */
class NotificationFlowTest extends TestCase
{
    use PrescriptionFixtures;

    private User $patientUser;

    private User $doctorUser;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->patientUser = User::factory()->patient()->create(['phone' => '+966500000111']);
        $this->doctorUser = User::factory()->doctor()->withMfa()->create();
        $doctor = Doctor::factory()->approved()->forUser($this->doctorUser)->create(['display_name_en' => 'Dr. Secret Cardiologist']);
        $patient = Patient::factory()->forUser($this->patientUser)->create();

        $this->appointment = Appointment::factory()->for($doctor, 'doctor')->for($patient, 'patient')->create();
    }

    private function push(): FakePushProvider
    {
        /** @var FakePushProvider */
        return app(PushProvider::class);
    }

    private function registerDevice(User $user, string $token = 'device-token-123456'): PushDevice
    {
        return PushDevice::create(['user_id' => $user->id, 'platform' => 'android', 'token' => $token, 'token_hash' => PushDevice::hashToken($token)]);
    }

    public function test_confirmation_notifies_patient_and_doctor_without_naming_the_doctor(): void
    {
        $this->registerDevice($this->patientUser);

        AppointmentConfirmed::dispatch($this->appointment);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->patientUser->id, 'type' => 'appointment.confirmed', 'category' => 'appointments']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->doctorUser->id, 'type' => 'appointment.new_booking']);

        $sms = $this->sms()->sentTo('+966500000111');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('10:00', $sms[0]['message']); // rendered in Asia/Riyadh
        $this->assertStringNotContainsString('Secret', $sms[0]['message']);
        $this->assertStringNotContainsString('Cardiologist', $sms[0]['message']);

        $this->assertCount(1, $this->push()->sent);
        $this->assertSame(['type' => 'appointment.confirmed', 'notification_id' => Notification::where('user_id', $this->patientUser->id)->value('id')], $this->push()->sent[0]['data']);

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($this->patientUser->email));
    }

    public function test_repeated_events_do_not_duplicate_notifications(): void
    {
        AppointmentConfirmed::dispatch($this->appointment);
        AppointmentConfirmed::dispatch($this->appointment);

        $this->assertSame(1, Notification::where('user_id', $this->patientUser->id)->where('type', 'appointment.confirmed')->count());
        $this->assertCount(1, $this->sms()->sentTo('+966500000111'));
    }

    public function test_a_doctor_who_already_saw_the_request_is_not_told_again_when_it_is_confirmed(): void
    {
        $this->appointment->forceFill(['status' => AppointmentStatus::Pending])->save();
        AppointmentBooked::dispatch($this->appointment);

        $this->assertSame(1, Notification::where('user_id', $this->doctorUser->id)->count());
        $this->assertSame(0, Notification::where('user_id', $this->patientUser->id)->count()); // not confirmed yet

        $this->appointment->forceFill(['status' => AppointmentStatus::Confirmed])->save();
        AppointmentConfirmed::dispatch($this->appointment);

        $this->assertSame(1, Notification::where('user_id', $this->doctorUser->id)->count());
        $this->assertSame(1, Notification::where('user_id', $this->patientUser->id)->count());
    }

    public function test_paid_bookings_stay_silent_until_payment_confirms_them(): void
    {
        $this->appointment->forceFill(['status' => AppointmentStatus::AwaitingPayment])->save();

        AppointmentBooked::dispatch($this->appointment);

        $this->assertSame(0, Notification::count());
    }

    public function test_cancellation_notifies_only_the_other_party(): void
    {
        $this->appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_by_user_id' => $this->patientUser->id, 'cancelled_at' => now()])->save();

        AppointmentCancelled::dispatch($this->appointment);

        $this->assertSame(0, Notification::where('user_id', $this->patientUser->id)->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $this->doctorUser->id, 'type' => 'appointment.cancelled']);
    }

    public function test_a_dependants_notifications_go_to_the_guardian_who_booked(): void
    {
        $guardian = User::factory()->patient()->create();
        $child = Patient::factory()->dependentOf($guardian)->create();
        $appointment = Appointment::factory()->for($this->appointment->doctor, 'doctor')->for($child, 'patient')
            ->at(now()->addDays(3))->create(['booked_by_user_id' => $guardian->id]);

        AppointmentConfirmed::dispatch($appointment);

        $this->assertDatabaseHas('notifications', ['user_id' => $guardian->id, 'type' => 'appointment.confirmed']);
    }

    public function test_suspended_accounts_receive_nothing(): void
    {
        $this->patientUser->forceFill(['status' => 'suspended'])->save();

        AppointmentConfirmed::dispatch($this->appointment);

        $this->assertSame(0, Notification::where('user_id', $this->patientUser->id)->count());
        $this->assertSame([], $this->sms()->sentTo('+966500000111'));
    }

    public function test_security_notices_are_sent_and_ignore_preferences(): void
    {
        foreach (['sms', 'email'] as $channel) {
            NotificationPreference::create(['user_id' => $this->patientUser->id, 'category' => 'security', 'channel' => $channel, 'enabled' => false]);
        }

        SuspiciousActivityDetected::dispatch($this->patientUser, SuspiciousActivityDetected::NEW_DEVICE, ['platform' => 'android']);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->patientUser->id, 'type' => 'security.new_device']);
        $this->assertCount(1, $this->sms()->sentTo('+966500000111'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($this->patientUser->email));
    }

    public function test_account_lock_notice_is_not_repeated_within_the_window(): void
    {
        SuspiciousActivityDetected::dispatch($this->patientUser, SuspiciousActivityDetected::ACCOUNT_LOCKED);
        SuspiciousActivityDetected::dispatch($this->patientUser, SuspiciousActivityDetected::ACCOUNT_LOCKED);

        $this->assertSame(1, Notification::where('type', 'security.account_locked')->count());
    }

    public function test_user_preferences_switch_channels_off_but_keep_the_in_app_entry(): void
    {
        NotificationPreference::create(['user_id' => $this->patientUser->id, 'category' => 'appointments', 'channel' => 'sms', 'enabled' => false]);

        AppointmentConfirmed::dispatch($this->appointment);

        $this->assertSame([], $this->sms()->sentTo('+966500000111'));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->patientUser->id, 'type' => 'appointment.confirmed']);
    }

    public function test_a_new_chat_message_alerts_the_other_participant_without_leaking_its_content(): void
    {
        $consultation = $this->activeConsultation('chat');
        $this->registerDevice($consultation->doctor->user, 'doctor-device-token');
        Sanctum::actingAs($consultation->patient->user, ['*']);

        $this->postJson("/api/v1/consultations/{$consultation->id}/messages", ['body' => 'My secret symptom is X'])->assertCreated();
        $this->postJson("/api/v1/consultations/{$consultation->id}/messages", ['body' => 'Another line'])->assertCreated();

        // One alert for a burst of messages.
        $this->assertSame(1, Notification::where('user_id', $consultation->doctor->user_id)->where('type', 'message.received')->count());
        $this->assertSame(0, Notification::where('user_id', $consultation->patient->user_id)->where('type', 'message.received')->count());

        $this->assertCount(1, $this->push()->sent);
        $this->assertStringNotContainsString('secret', json_encode($this->push()->sent));
        $this->assertStringNotContainsString('symptom', json_encode(Notification::all()->toArray()));
    }

    public function test_prescription_updates_do_not_reveal_medications(): void
    {
        $prescription = $this->issuedPrescription();

        $notification = Notification::where('type', 'prescription.status_changed')->where('user_id', $prescription->patient->user_id)->firstOrFail();

        $this->assertEquals(['prescription_id' => $prescription->id, 'status' => 'issued'], $notification->params); // jsonb does not keep key order
        $this->assertStringNotContainsString('Amoxicillin', json_encode(Notification::all()->toArray()));
    }

    public function test_pharmacy_workflow_notifies_branch_staff_then_the_patient(): void
    {
        $prescription = $this->issuedPrescription();
        $this->grantPharmacyConsent($prescription);
        [$pharmacy, $branch] = $this->approvedPharmacy();
        $pharmacist = $this->pharmacyUser($pharmacy);
        $otherPharmacist = $this->pharmacyUser($this->approvedPharmacy()[0]);

        Sanctum::actingAs($prescription->patient->user, ['*']);
        $requestId = $this->postJson("/api/v1/prescriptions/{$prescription->id}/dispense-requests", ['branch_id' => $branch->id])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('notifications', ['user_id' => $pharmacist->id, 'type' => 'pharmacy.request_received']);
        $this->assertSame(0, Notification::where('user_id', $otherPharmacist->id)->count());
        // The patient made the request themselves: no echo.
        $this->assertSame(0, Notification::where('user_id', $prescription->patient->user_id)->where('type', 'pharmacy.request_updated')->count());

        Sanctum::actingAs($pharmacist, ['*']);
        $this->postJson("/api/v1/pharmacy/dispense-requests/{$requestId}/status", ['status' => 'received'])->assertOk();

        $update = Notification::where('user_id', $prescription->patient->user_id)->where('type', 'pharmacy.request_updated')->firstOrFail();
        $this->assertSame('received', $update->params['status']);
    }

    public function test_notification_failures_never_break_the_business_action(): void
    {
        // A broken provider binding makes every delivery throw; the booking flow must not.
        $this->app->instance(SmsProvider::class, new class implements SmsProvider
        {
            public function send(string $to, string $message): string
            {
                throw new \RuntimeException('gateway down');
            }
        });

        AppointmentConfirmed::dispatch($this->appointment); // must not throw

        $this->assertDatabaseHas('notifications', ['user_id' => $this->patientUser->id, 'type' => 'appointment.confirmed']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'failed', 'error_code' => 'RuntimeException']);
    }
}
