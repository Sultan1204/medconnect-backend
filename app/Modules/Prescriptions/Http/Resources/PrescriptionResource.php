<?php

namespace App\Modules\Prescriptions\Http\Resources;

use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prescription for the prescriber and the patient side. Every response
 * states its legal standing; clients must display `legal_notice`.
 *
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPrescriber = $request->user()?->id === $this->prescriber_user_id;
        $validity = $this->legalValidity();
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'patient_id' => $this->patient_id,
            'status' => $this->status->value,
            'legal_validity' => $validity,
            'legal_notice' => match ($validity) {
                'issued_by_authorized_service' => null,
                'simulated_not_valid' => $ar ? 'وصفة تجريبية (محاكاة) - ليست وصفة طبية صالحة.' : 'SIMULATED - not a valid prescription. Issued by a development gateway.',
                default => $ar ? 'ليست وصفة طبية صالحة - لم تصدر عبر خدمة الوصفات الإلكترونية المعتمدة.' : 'Not a valid prescription - not issued through an authorised e-prescribing service.',
            },
            'clinical_indication' => $this->clinical_indication,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (PrescriptionItem $i) => [
                'id' => $i->id,
                'catalog_system' => $i->catalog_system,
                'catalog_code' => $i->catalog_code,
                'medication_name' => $i->medication_name,
                'strength' => $i->strength,
                'dosage_form' => $i->dosage_form,
                'dose' => $i->dose,
                'route' => $i->route,
                'frequency' => $i->frequency,
                'duration_days' => $i->duration_days,
                'quantity' => $i->quantity,
                'quantity_unit' => $i->quantity_unit,
                'instructions' => $i->instructions,
                'is_controlled' => $i->is_controlled,
            ])),
            'safety_check' => $this->when($isPrescriber, fn () => [
                'status' => $this->safety_check_status,
                'findings' => $this->safety_check_result ?? [],
                'warnings_acknowledged_at' => $this->warnings_acknowledged_at?->toIso8601ZuluString(),
            ]),
            'external_reference' => $this->external_reference,
            'status_reason' => $this->status_reason,
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'prescriber_doctor_id' => $this->prescriber_doctor_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
