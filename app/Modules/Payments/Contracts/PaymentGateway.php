<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Data\RefundResult;
use App\Modules\Payments\Data\WebhookEvent;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use Illuminate\Http\Request;

/**
 * Payment provider integration (hosted checkout - the platform never sees
 * card data). An adapter for a licensed Saudi payment provider must be
 * reviewed against SAMA requirements and the provider's contract before
 * activation; marketplace fund flows/payouts additionally need confirmation
 * that the provider supports them.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Creates a hosted checkout for the payment. Must be idempotent on
     * $payment->idempotency_key (passed to the provider).
     */
    public function createCheckout(Payment $payment): CheckoutSession;

    /**
     * Verifies the signature (and freshness) of a webhook and normalises it.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Requests a refund. Must be idempotent on $refund->idempotency_key.
     */
    public function refund(Payment $payment, Refund $refund): RefundResult;
}
