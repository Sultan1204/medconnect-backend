<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tz = (string) config('medconnect.display_timezone');
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'consultation_type' => $this->consultation_type,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'local_start' => $this->starts_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'timezone' => $tz,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'age' => $this->patient->age(),
                'gender' => $this->patient->gender,
            ]),
            'doctor' => $this->whenLoaded('doctor', fn () => [
                'id' => $this->doctor->id,
                'name' => app()->getLocale() === 'ar' ? ($this->doctor->display_name_ar ?? $this->doctor->display_name_en) : $this->doctor->display_name_en,
                'title' => $this->doctor->title,
            ]),
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->currency,
                'is_free' => $this->isFree(),
            ],
            'payment_due_at' => $this->payment_due_at?->toIso8601ZuluString(),
            'cancellation_policy' => $this->cancellation_policy,
            'cancellation' => $this->when($this->cancelled_at !== null, fn () => [
                'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
                'by' => $this->cancelled_by_role,
                'reason' => $this->cancellation_reason,
                'refund_percent' => $this->refund_percent,
            ]),
            'can_cancel' => $user !== null && $this->status->isCancellable() && $user->can('cancel', $this->resource),
            'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
