<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Gateways\MockPaymentGateway;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Development-only stand-in for a provider's hosted checkout page. It emits
 * a correctly signed webhook through the real webhook endpoint. Routes are
 * registered only when the mock gateway is active outside production.
 *
 * @tags Development
 */
class MockCheckoutController
{
    /**
     * Mock checkout page.
     *
     * @unauthenticated
     */
    public function show(Payment $payment): JsonResponse
    {
        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'how_to_complete' => 'POST this URL with {"outcome": "succeeded"} or {"outcome": "failed"}.',
        ]]);
    }

    /**
     * Simulate the shopper completing or failing payment.
     *
     * @unauthenticated
     */
    public function complete(Request $request, Payment $payment): JsonResponse
    {
        $outcome = $request->validate(['outcome' => ['required', Rule::in(['succeeded', 'failed'])]])['outcome'];

        [$body, $headers] = MockPaymentGateway::signedRequest([
            'id' => 'evt_'.Str::uuid7(),
            'type' => 'payment.'.$outcome,
            'payment_id' => $payment->provider_payment_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'failure_code' => $outcome === 'failed' ? 'card_declined' : null,
        ]);

        $server = collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
        $response = app()->handle(Request::create('/api/v1/webhooks/payments', 'POST', server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body));

        return response()->json(['data' => [
            'webhook_status' => $response->getStatusCode(),
            'payment_status' => $payment->fresh()?->status->value,
        ]]);
    }
}
