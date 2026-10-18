<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdministrator() ?? false;

        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'refunded_amount' => $this->refunded_amount,
            'provider' => $this->provider,
            'failure_code' => $this->failure_code,
            'captured_at' => $this->captured_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn (Refund $r) => [
                'id' => $r->id,
                'amount' => $r->amount,
                'status' => $r->status,
                'reason' => $r->reason,
                'succeeded_at' => $r->succeeded_at?->toIso8601ZuluString(),
            ])),
            'commission_rate_bps' => $this->when($isAdmin, $this->commission_rate_bps),
            'provider_payment_id' => $this->when($isAdmin, $this->provider_payment_id),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
