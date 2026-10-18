<?php

namespace Tests\Feature\Payments;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Scheduling\Actions\CancelAppointment;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use PaymentFixtures;

    private Appointment $appointment;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        $patient = Patient::factory()->create();
        $this->payer = $patient->user;
        $this->appointment = Appointment::factory()->for($patient)->status(AppointmentStatus::AwaitingPayment)->create([
            'booked_by_user_id' => $this->payer->id,
            'payment_due_at' => now()->addMinutes(15),
            'confirmed_at' => null,
            'price_amount' => 15000,
            'cancellation_policy' => ['free_cancellation_hours' => 24, 'late_cancellation_refund_percent' => 50],
        ]);
        Sanctum::actingAs($this->payer, ['*']);
    }

    private function checkout(string $key = 'checkout-key-0001', ?string $appointmentId = null): TestResponse
    {
        return $this->postJson('/api/v1/payments/checkout', ['appointment_id' => $appointmentId ?? $this->appointment->id], ['Idempotency-Key' => $key]);
    }

    private function paidPayment(): Payment
    {
        $id = $this->checkout()->assertCreated()->json('data.payment.id');
        $payment = Payment::findOrFail($id);
        $this->succeed($payment)->assertOk()->assertJsonPath('result', 'processed');

        return $payment->fresh();
    }

    public function test_checkout_requires_an_idempotency_key(): void
    {
        $this->assertErrorCode($this->postJson('/api/v1/payments/checkout', ['appointment_id' => $this->appointment->id]), 400, 'idempotency_key_required');
    }

    public function test_checkout_is_idempotent(): void
    {
        $first = $this->checkout()->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.amount', 15000)
            ->json('data');
        $this->assertNotEmpty($first['checkout']['url']);

        $second = $this->checkout()->assertOk()->json('data');
        $this->assertSame($first['payment']['id'], $second['payment']['id']);
        $this->assertSame(1, Payment::count());

        $other = Appointment::factory()->for(Patient::where('user_id', $this->payer->id)->first())->status(AppointmentStatus::AwaitingPayment)
            ->create(['payment_due_at' => now()->addMinutes(10), 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addMinutes(30)]);
        $this->assertErrorCode($this->checkout(appointmentId: $other->id), 409, 'idempotency_key_reused');
    }

    public function test_only_the_patient_side_can_pay(): void
    {
        Sanctum::actingAs(User::factory()->patient()->create(), ['*']);

        $this->assertErrorCode($this->checkout('another-key-0001'), 403, 'forbidden');
    }

    public function test_appointments_not_awaiting_payment_cannot_be_paid(): void
    {
        $this->appointment->forceFill(['status' => AppointmentStatus::Confirmed])->save();

        $this->assertErrorCode($this->checkout(), 409, 'appointment_not_payable');
    }

    public function test_verified_webhook_captures_payment_and_confirms_the_appointment(): void
    {
        $payment = $this->paidPayment();

        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame(AppointmentStatus::Confirmed, $this->appointment->fresh()->status);
        $this->assertNotNull(Consultation::where('appointment_id', $this->appointment->id)->first());
        $this->getJson("/api/v1/payments/{$payment->id}")->assertOk()->assertJsonPath('data.status', 'captured');
    }

    public function test_payment_stays_pending_without_a_webhook(): void
    {
        $id = $this->checkout()->json('data.payment.id');

        $this->getJson("/api/v1/payments/{$id}")->assertJsonPath('data.status', 'pending');
        $this->assertSame(AppointmentStatus::AwaitingPayment, $this->appointment->fresh()->status);
    }

    public function test_invalid_or_stale_signatures_are_rejected_without_side_effects(): void
    {
        $payment = Payment::findOrFail($this->checkout()->json('data.payment.id'));
        $event = ['type' => 'payment.succeeded', 'payment_id' => $payment->provider_payment_id, 'amount' => 15000, 'currency' => 'SAR'];

        $this->assertErrorCode($this->postWebhook($event, signatureOverride: 't='.time().',v1=deadbeef'), 400, 'invalid_signature');
        $this->assertErrorCode($this->postWebhook($event, timestamp: time() - 600), 400, 'invalid_signature');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentWebhookEvent::count());
        $this->assertDatabaseHas('integration_events', ['integration' => 'payments', 'error_code' => 'invalid_signature']);
    }

    public function test_duplicate_webhooks_are_processed_once(): void
    {
        $payment = Payment::findOrFail($this->checkout()->json('data.payment.id'));

        $this->succeed($payment, eventId: 'evt_same')->assertJsonPath('result', 'processed');
        $this->succeed($payment, eventId: 'evt_same')->assertOk()->assertJsonPath('result', 'duplicate');
        // A different event for the same capture is harmless too.
        $this->succeed($payment)->assertOk();

        $this->assertSame(PaymentStatus::Captured, $payment->fresh()->status);
        $this->assertSame(2, PaymentWebhookEvent::count());
        $this->assertSame(1, AuditLog::where('action', 'appointments.paid')->count());
    }

    public function test_amount_mismatch_requires_review_and_does_not_confirm(): void
    {
        $payment = Payment::findOrFail($this->checkout()->json('data.payment.id'));

        $this->succeed($payment, amount: 100)->assertOk()->assertJsonPath('result', 'amount_mismatch');

        $this->assertSame(PaymentStatus::ReviewRequired, $payment->fresh()->status);
        $this->assertSame(AppointmentStatus::AwaitingPayment, $this->appointment->fresh()->status);
    }

    public function test_failed_payments_leave_the_appointment_payable(): void
    {
        $payment = Payment::findOrFail($this->checkout()->json('data.payment.id'));

        $this->postWebhook(['type' => 'payment.failed', 'payment_id' => $payment->provider_payment_id, 'failure_code' => 'insufficient_funds'])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('insufficient_funds', $payment->fresh()->failure_code);
        $this->checkout('retry-key-00001')->assertCreated();
    }

    public function test_payment_after_the_hold_expired_is_refunded_automatically(): void
    {
        $payment = Payment::findOrFail($this->checkout()->json('data.payment.id'));
        $this->travel(16)->minutes();
        $this->artisan('appointments:expire-unpaid');

        $this->succeed($payment)->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(AppointmentStatus::Expired, $this->appointment->fresh()->status);
        $this->assertDatabaseHas('refunds', ['payment_id' => $payment->id, 'reason' => 'late_payment', 'status' => 'succeeded', 'amount' => 15000]);
    }

    public function test_early_cancellation_refunds_in_full(): void
    {
        $payment = $this->paidPayment();

        app(CancelAppointment::class)->handle($this->appointment->fresh(), $this->payer, CancelAppointment::BY_PATIENT, null);

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(15000, $payment->fresh()->refunded_amount);
    }

    public function test_late_cancellation_refunds_the_policy_percentage(): void
    {
        $payment = $this->paidPayment();
        $this->travelTo($this->appointment->starts_at->copy()->subHours(2));

        app(CancelAppointment::class)->handle($this->appointment->fresh(), $this->payer, CancelAppointment::BY_PATIENT, null);

        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);
        $this->assertSame(7500, $payment->fresh()->refunded_amount);
    }

    public function test_refund_confirmed_later_by_webhook(): void
    {
        $payment = $this->paidPayment();
        $refund = $payment->refunds()->create(['amount' => 5000, 'currency' => 'SAR', 'reason' => 'admin', 'idempotency_key' => 'manual-1']);
        $refund->forceFill(['provider_refund_id' => 'ref_external_1'])->save();

        $this->postWebhook(['type' => 'refund.succeeded', 'refund_id' => 'ref_external_1'])->assertOk()->assertJsonPath('result', 'processed');

        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame(5000, $payment->fresh()->refunded_amount);
    }

    public function test_mock_checkout_page_completes_payment_through_the_real_webhook(): void
    {
        $url = $this->checkout()->json('data.checkout.url');

        $this->postJson($url, ['outcome' => 'succeeded'])->assertOk()
            ->assertJsonPath('data.webhook_status', 200)
            ->assertJsonPath('data.payment_status', 'captured');

        $this->assertSame(AppointmentStatus::Confirmed, $this->appointment->fresh()->status);
    }
}
