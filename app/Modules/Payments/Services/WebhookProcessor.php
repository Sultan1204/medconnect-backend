<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookEvent;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Jobs\RefundLatePayment;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Scheduling\Actions\TransitionAppointment;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The provider webhook is the only source of truth for payment outcomes.
 * Processing is idempotent: each provider event id is handled at most once.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RefundService $refunds,
        private readonly TransitionAppointment $appointments,
        private readonly IntegrationLogger $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string processed|duplicate|ignored|error code
     */
    public function handle(Request $request): string
    {
        try {
            $event = $this->gateway->parseWebhook($request);
        } catch (InvalidWebhookSignature) {
            $this->integrations->inbound('payments', 'webhook', 'failure', errorCode: 'invalid_signature');

            throw ApiException::make('invalid_signature', 'Webhook signature verification failed.', 400);
        }

        if ($event->eventId === '' || $event->type === '') {
            throw ApiException::make('invalid_payload', 'Webhook payload is missing required fields.', 400);
        }

        try {
            // Own savepoint: a duplicate insert must not abort an enclosing transaction.
            $record = DB::transaction(fn () => PaymentWebhookEvent::create([
                'provider' => $this->gateway->name(),
                'provider_event_id' => $event->eventId,
                'type' => $event->type,
                'signature_valid' => true,
                'payload' => $event->toArray(),
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return 'duplicate';
            }

            throw $e;
        }

        $outcome = DB::transaction(function () use ($record, $event) {
            /** @var PaymentWebhookEvent $locked */
            $locked = PaymentWebhookEvent::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->processed_at !== null) {
                return 'duplicate';
            }

            [$outcome, $paymentId, $error] = match ($event->type) {
                WebhookEvent::PAYMENT_SUCCEEDED => $this->paymentSucceeded($event),
                WebhookEvent::PAYMENT_FAILED => $this->paymentFailed($event),
                WebhookEvent::REFUND_SUCCEEDED, WebhookEvent::REFUND_FAILED => $this->refundOutcome($event),
                default => ['ignored', null, 'ignored_type'],
            };

            $locked->forceFill(['processed_at' => now(), 'payment_id' => $paymentId, 'processing_error' => $error])->save();

            return $outcome;
        });

        $this->integrations->inbound('payments', 'webhook:'.$event->type, $outcome === 'processed' ? 'success' : 'failure', reference: $event->eventId, errorCode: $outcome === 'processed' ? null : $outcome);

        return $outcome;
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function paymentSucceeded(WebhookEvent $event): array
    {
        $payment = $this->findPayment($event);
        if ($payment === null) {
            return ['unknown_payment', null, 'unknown_payment'];
        }

        if ($payment->status->isCaptured()) {
            return ['processed', $payment->id, null];
        }

        if ($event->amount !== $payment->amount || $event->currency !== $payment->currency) {
            $payment->forceFill(['status' => PaymentStatus::ReviewRequired, 'failure_code' => 'amount_mismatch'])->save();
            $this->audit->failure('payments.amount_mismatch', $payment, ['actor' => null, 'patient_id' => $payment->patient_id]);

            return ['amount_mismatch', $payment->id, 'amount_mismatch'];
        }

        $payment->forceFill(['status' => PaymentStatus::Captured, 'captured_at' => now()])->save();
        $this->audit->record('payments.captured', $payment, ['actor' => null, 'patient_id' => $payment->patient_id]);

        /** @var Appointment $appointment */
        $appointment = Appointment::whereKey($payment->appointment_id)->lockForUpdate()->firstOrFail();

        if ($appointment->status === AppointmentStatus::AwaitingPayment) {
            $this->appointments->markPaid($appointment);
        } else {
            // Paid after the hold expired or the booking was cancelled: give the money back.
            DB::afterCommit(fn () => RefundLatePayment::dispatch($payment->id));
        }

        DB::afterCommit(fn () => PaymentCaptured::dispatch($payment));

        return ['processed', $payment->id, null];
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function paymentFailed(WebhookEvent $event): array
    {
        $payment = $this->findPayment($event);
        if ($payment === null) {
            return ['unknown_payment', null, 'unknown_payment'];
        }

        if ($payment->status === PaymentStatus::Pending) {
            $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_code' => $event->failureCode ?? 'declined'])->save();
            $this->audit->failure('payments.failed', $payment, ['actor' => null, 'patient_id' => $payment->patient_id]);
        }

        return ['processed', $payment->id, null];
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function refundOutcome(WebhookEvent $event): array
    {
        $refund = $event->providerRefundId !== null
            ? Refund::where('provider_refund_id', $event->providerRefundId)->first()
            : null;

        if ($refund === null) {
            return ['unknown_refund', null, 'unknown_refund'];
        }

        $event->type === WebhookEvent::REFUND_SUCCEEDED
            ? $this->refunds->markSucceeded($refund)
            : $this->refunds->markFailed($refund, $event->failureCode);

        return ['processed', $refund->payment_id, null];
    }

    private function findPayment(WebhookEvent $event): ?Payment
    {
        return $event->providerPaymentId === null ? null : Payment::query()
            ->where('provider', $this->gateway->name())
            ->where('provider_payment_id', $event->providerPaymentId)
            ->lockForUpdate()
            ->first();
    }
}
