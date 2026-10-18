<?php

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Support\Money\Money;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Refunds `refund_percent` of the captured amount, as fixed by the
 * cancellation policy snapshotted at booking. Queued: the provider call
 * never blocks the cancellation request.
 */
class RefundCancelledAppointment implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    public function __construct(private readonly RefundService $refunds) {}

    public function handle(AppointmentCancelled $event): void
    {
        $percent = $event->appointment->refund_percent;
        if ($percent === null || $percent <= 0) {
            return;
        }

        $payment = Payment::where('appointment_id', $event->appointment->id)
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded])
            ->first();

        if ($payment === null) {
            return;
        }

        $amount = min(Money::percent($payment->amount, $percent), $payment->refundableAmount());

        if ($amount > 0) {
            $this->refunds->refund($payment, $amount, 'cancellation', 'cancellation:'.$event->appointment->id);
        }
    }
}
