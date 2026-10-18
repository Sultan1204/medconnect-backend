<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A payment captured after its appointment expired or was cancelled is
 * refunded in full automatically.
 */
class RefundLatePayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $paymentId) {}

    public function handle(RefundService $refunds): void
    {
        $payment = Payment::findOrFail($this->paymentId);

        if ($payment->refundableAmount() > 0) {
            $refunds->refund($payment, $payment->refundableAmount(), 'late_payment', 'late_payment:'.$payment->id);
        }
    }
}
