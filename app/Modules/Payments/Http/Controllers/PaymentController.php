<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CheckoutService;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Payments
 */
class PaymentController extends Controller
{
    /**
     * Start checkout for an appointment.
     *
     * Requires an `Idempotency-Key` header (8-100 chars). Redirect the
     * patient to `checkout.url` (or use `checkout.client_data` with the
     * provider SDK). The appointment is confirmed only after the provider's
     * verified webhook arrives - never on the client's word.
     */
    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['appointment_id' => ['required', 'uuid']]);
        $key = (string) $request->header('Idempotency-Key');

        if (! preg_match('/^[A-Za-z0-9\-_]{8,100}$/', $key)) {
            throw ApiException::make('idempotency_key_required', 'A valid Idempotency-Key header is required.', 400);
        }

        $appointment = Appointment::with('patient')->findOrFail($data['appointment_id']);
        [$payment, $session] = $checkout->checkout($request->user(), $appointment, $key);

        return response()->json(['data' => [
            'payment' => new PaymentResource($payment),
            'checkout' => [
                'url' => $session->checkoutUrl,
                'expires_at' => $session->expiresAt->toIso8601ZuluString(),
                'client_data' => $session->clientData,
            ],
        ]], $payment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Payment status.
     */
    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load('refunds'));
    }
}
