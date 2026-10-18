<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Starts a hosted checkout for an appointment awaiting payment. The payment
 * becomes `captured` only via a verified provider webhook.
 */
class CheckoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly IntegrationLogger $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{Payment, CheckoutSession}
     */
    public function checkout(User $payer, Appointment $appointment, string $idempotencyKey): array
    {
        if (($existing = $this->replay($payer, $idempotencyKey, $appointment)) !== null) {
            return [$existing, $this->gateway->createCheckout($existing)];
        }

        if (Gate::forUser($payer)->denies('actFor', $appointment->patient)) {
            throw ApiException::forbidden('You cannot pay for this appointment.');
        }

        if ($appointment->status !== AppointmentStatus::AwaitingPayment || $appointment->payment_due_at?->isPast() !== false) {
            throw ApiException::conflict('appointment_not_payable', 'This appointment is not awaiting payment.');
        }

        try {
            // Own savepoint: a duplicate-key race must not abort an enclosing transaction.
            $payment = DB::transaction(fn () => Payment::create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'payer_user_id' => $payer->id,
                'amount' => $appointment->price_amount,
                'currency' => $appointment->currency,
                'commission_rate_bps' => (int) config('medconnect.payments.commission_bps'),
                'status' => PaymentStatus::Pending,
                'provider' => $this->gateway->name(),
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $appointment->payment_due_at,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && ($existing = $this->replay($payer, $idempotencyKey, $appointment)) !== null) {
                return [$existing, $this->gateway->createCheckout($existing)];
            }

            throw $e;
        }

        $session = $this->integrations->call(
            'payments', 'create_checkout', $payment,
            fn () => $this->gateway->createCheckout($payment),
            fn (CheckoutSession $s) => $s->providerPaymentId,
        );

        $payment->forceFill(['provider_payment_id' => $session->providerPaymentId])->save();

        $this->audit->record('payments.checkout_started', $payment, [
            'actor' => $payer,
            'patient_id' => $appointment->patient_id,
            'metadata' => ['amount' => $payment->amount, 'currency' => $payment->currency],
        ]);

        return [$payment, $session];
    }

    /**
     * Reads current database state, so repeated calls may differ (e.g. after a race).
     *
     * @phpstan-impure
     */
    private function replay(User $payer, string $key, Appointment $appointment): ?Payment
    {
        $existing = Payment::where('payer_user_id', $payer->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && $existing->appointment_id !== $appointment->id) {
            throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
        }

        return $existing;
    }
}
