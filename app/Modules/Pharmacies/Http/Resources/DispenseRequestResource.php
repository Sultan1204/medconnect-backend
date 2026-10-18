<?php

namespace App\Modules\Pharmacies\Http\Resources;

use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Prescriptions\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimum necessary data for dispensing: the medication lines, who the
 * patient is (to verify identity at pickup) and who prescribed it. No
 * consultation notes, history or clinical indication.
 *
 * @mixin DispenseRequest
 */
class DispenseRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'prescription' => $this->whenLoaded('prescription', fn () => [
                'id' => $this->prescription->id,
                'status' => $this->prescription->status->value,
                'legal_validity' => $this->prescription->legalValidity(),
                'external_reference' => $this->prescription->external_reference,
                'issued_at' => $this->prescription->issued_at?->toIso8601ZuluString(),
                'expires_at' => $this->prescription->expires_at?->toIso8601ZuluString(),
                'prescriber' => $this->prescription->relationLoaded('prescriber') ? [
                    'name' => $this->prescription->prescriber->display_name_en,
                    'license_number' => $this->prescription->prescriber->license_number,
                    'licensing_authority' => $this->prescription->prescriber->licensing_authority,
                ] : null,
                'items' => $this->prescription->relationLoaded('items')
                    ? $this->prescription->items->map(fn (PrescriptionItem $i) => [
                        'medication_name' => $i->medication_name,
                        'catalog_system' => $i->catalog_system,
                        'catalog_code' => $i->catalog_code,
                        'strength' => $i->strength,
                        'dosage_form' => $i->dosage_form,
                        'dose' => $i->dose,
                        'route' => $i->route,
                        'frequency' => $i->frequency,
                        'duration_days' => $i->duration_days,
                        'quantity' => $i->quantity,
                        'quantity_unit' => $i->quantity_unit,
                        'instructions' => $i->instructions,
                    ]) : null,
            ]),
            'patient' => $this->whenLoaded('patient', fn () => [
                'full_name' => $this->patient->full_name,
                'age' => $this->patient->age(),
                'gender' => $this->patient->gender,
            ]),
            'rejection_reason' => $this->rejection_reason,
            'pharmacy_note' => $this->pharmacy_note,
            'ready_at' => $this->ready_at?->toIso8601ZuluString(),
            'dispensed_at' => $this->dispensed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
