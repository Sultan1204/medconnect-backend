<?php

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @tags Admin - Payments
 */
class PaymentAdminController extends Controller
{
    /**
     * Search payments.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'appointment_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $payments = Payment::query()
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['appointment_id'] ?? null, fn ($q, $id) => $q->where('appointment_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->with('refunds')
            ->latest()
            ->paginate(50);

        return PaymentResource::collection($payments);
    }

    /**
     * Payment detail.
     */
    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load('refunds'));
    }

    /**
     * Issue a refund (requires step-up MFA).
     *
     * Partial refunds are allowed up to the remaining refundable balance.
     * Send an `Idempotency-Key` header to make retries safe.
     */
    public function refund(Request $request, Payment $payment, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(['admin', 'dispute', 'service_issue'])],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $key = 'admin:'.($request->header('Idempotency-Key') ?: Str::uuid7());
        $refund = $refunds->refund($payment, $data['amount'], $data['reason'], $key, $request->user(), $data['note']);

        return response()->json(['data' => [
            'refund_id' => $refund->id,
            'status' => $refund->status,
            'payment' => new PaymentResource($payment->fresh()?->load('refunds')),
        ]], 201);
    }
}
