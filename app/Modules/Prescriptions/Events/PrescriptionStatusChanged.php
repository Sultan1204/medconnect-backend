<?php

namespace App\Modules\Prescriptions\Events;

use App\Modules\Prescriptions\Enums\PrescriptionStatus;
use App\Modules\Prescriptions\Models\Prescription;
use Illuminate\Foundation\Events\Dispatchable;

class PrescriptionStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Prescription $prescription,
        public readonly ?PrescriptionStatus $from,
    ) {}
}
