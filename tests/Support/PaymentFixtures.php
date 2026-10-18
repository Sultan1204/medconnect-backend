<?php

namespace Tests\Support;

use App\Modules\Payments\Gateways\MockPaymentGateway;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait PaymentFixtures
{
    /**
     * @param  array<string, mixed>  $event
     */
    protected function postWebhook(array $event, ?int $timestamp = null, ?string $signatureOverride = null): TestResponse
    {
        [$body, $headers] = MockPaymentGateway::signedRequest($event + ['id' => 'evt_'.Str::uuid7()], $timestamp);

        if ($signatureOverride !== null) {
            $headers[MockPaymentGateway::SIGNATURE_HEADER] = $signatureOverride;
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/payments', [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    protected function succeed(Payment $payment, ?int $amount = null, ?string $eventId = null): TestResponse
    {
        return $this->postWebhook(array_filter([
            'id' => $eventId,
            'type' => 'payment.succeeded',
            'payment_id' => $payment->provider_payment_id,
            'amount' => $amount ?? $payment->amount,
            'currency' => $payment->currency,
        ], fn ($v) => $v !== null));
    }
}
