<?php

namespace App\Modules\Prescriptions\Console;

use App\Modules\Prescriptions\Services\PrescriptionWorkflow;
use Illuminate\Console\Command;

class ExpirePrescriptions extends Command
{
    protected $signature = 'prescriptions:expire';

    protected $description = 'Mark issued prescriptions past their validity as expired.';

    public function handle(PrescriptionWorkflow $workflow): int
    {
        $this->info($workflow->expireIssued().' prescription(s) expired.');

        return self::SUCCESS;
    }
}
