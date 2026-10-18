<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\IntegrationLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\RefundResult;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly IntegrationLogger $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Idempotent on $idempotencyKey. Pending refunds count against the
     * refundable balance so concurrent requests can never over-refund.
     */
    public function refund(Payment $payment, int $amount, string $reason, string $idempotencyKey, ?User $requestedBy = null, ?string $note = null): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $reason, $idempotencyKey, $requestedBy, $note) {
            if (($existing = Refund::where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $pending = (int) $locked->refunds()->where('status', 'pending')->sum('amount');
            $available = $locked->refundableAmount() - $pending;

            if ($amount <= 0 || $amount > $available) {
                throw ApiException::make('refund_exceeds_balance', 'The refund amount exceeds the refundable balance.', 422, ['refundable' => max(0, $available)]);
            }

            $refund = Refund::create([
                'payment_id' => $locked->id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'reason' => $reason,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'requested_by_user_id' => $requestedBy?->id,
            ]);

            $this->audit->record('payments.refund_requested', $refund, [
                'actor' => $requestedBy,
                'patient_id' => $locked->patient_id,
                'metadata' => ['amount' => $amount, 'reason' => $reason],
            ]);

            return $refund;
        });

        if ($refund->status !== 'pending' || $refund->provider_refund_id !== null) {
            return $refund;
        }

        // The provider call happens outside the transaction (no locks held during network I/O).
        try {
            $result = $this->integrations->call('payments', 'refund', $refund,
                fn () => $this->gateway->refund($payment, $refund),
                fn (RefundResult $r) => $r->providerRefundId,
            );
        } catch (Throwable $e) {
            report($e);
            $refund->forceFill(['failure_code' => 'provider_error'])->save();

            return $refund; // stays pending; retried by operations or confirmed by webhook
        }

        $refund->forceFill(['provider_refund_id' => $result->providerRefundId])->save();

        return match ($result->status) {
            'succeeded' => $this->markSucceeded($refund),
            'failed' => $this->markFailed($refund, $result->failureCode),
            default => $refund,
        };
    }

    public function markSucceeded(Refund $refund): Refund
    {
        $refund = DB::transaction(function () use ($refund) {
            /** @var Refund $locked */
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked;
            }

            /** @var Payment $payment */
            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $refunded = $payment->refunded_amount + $locked->amount;

            $payment->forceFill([
                'refunded_amount' => $refunded,
                'status' => $refunded >= $payment->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ])->save();

            $locked->forceFill(['status' => 'succeeded', 'succeeded_at' => now()])->save();
            $this->audit->record('payments.refund_succeeded', $locked, ['actor' => null, 'patient_id' => $payment->patient_id]);

            DB::afterCommit(fn () => RefundSucceeded::dispatch($locked));

            return $locked;
        });

        return $refund;
    }

    public function markFailed(Refund $refund, ?string $code): Refund
    {
        if ($refund->status === 'pending') {
            $refund->forceFill(['status' => 'failed', 'failure_code' => $code ?? 'unknown'])->save();
            $this->audit->failure('payments.refund_failed', $refund, ['actor' => null]);
        }

        return $refund;
    }
}
