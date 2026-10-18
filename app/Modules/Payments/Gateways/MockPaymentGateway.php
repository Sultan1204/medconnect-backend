<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Data\RefundResult;
use App\Modules\Payments\Data\WebhookEvent;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Local/test provider. Webhooks are signed exactly like a typical real
 * provider (HMAC-SHA256 over "timestamp.body", replay window), so the
 * verification path is exercised end to end. Refuses to run in production.
 *
 * Header:  X-Mock-Signature: t=<unix>,v1=<hex hmac>
 */
class MockPaymentGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Mock-Signature';

    public function name(): string
    {
        return 'mock';
    }

    public function createCheckout(Payment $payment): CheckoutSession
    {
        $this->guard();

        // Deterministic per idempotency key, as real providers do.
        $id = 'mock_pay_'.substr(hash('sha256', $payment->payer_user_id.'|'.$payment->idempotency_key), 0, 24);

        return new CheckoutSession(
            providerPaymentId: $id,
            checkoutUrl: url('/api/v1/dev/mock-payments/'.$payment->id),
            expiresAt: CarbonImmutable::instance($payment->expires_at ?? now()->addMinutes(15)),
            clientData: ['provider' => 'mock', 'amount' => $payment->amount, 'currency' => $payment->currency],
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $this->guard();

        $header = (string) $request->header(self::SIGNATURE_HEADER);
        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if (abs(time() - $timestamp) > (int) config('medconnect.payments.webhook_tolerance_seconds')) {
            throw new InvalidWebhookSignature('Webhook timestamp outside tolerance.');
        }

        if (! hash_equals(self::sign($request->getContent(), $timestamp), $signature)) {
            throw new InvalidWebhookSignature('Webhook signature mismatch.');
        }

        $data = $request->json()->all();

        return new WebhookEvent(
            eventId: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            providerPaymentId: $data['payment_id'] ?? null,
            providerRefundId: $data['refund_id'] ?? null,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            failureCode: $data['failure_code'] ?? null,
        );
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        $this->guard();

        return new RefundResult('mock_ref_'.substr(hash('sha256', $refund->idempotency_key), 0, 24), 'succeeded');
    }

    public static function sign(string $body, int $timestamp): string
    {
        $secret = (string) config('medconnect.payments.webhook_secret');

        if ($secret === '') {
            throw new RuntimeException('PAYMENT_WEBHOOK_SECRET is not configured.');
        }

        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: array<string, string>}
     */
    public static function signedRequest(array $event, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return [$body, [self::SIGNATURE_HEADER => "t={$timestamp},v1=".self::sign($body, $timestamp), 'Content-Type' => 'application/json']];
    }

    private function guard(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock payment gateway must not be used in production.');
        }
    }
}
