<?php

namespace App\Modules\Consultations\Console;

use App\Modules\Consultations\Services\ConsultationLifecycle;
use Illuminate\Console\Command;

class EnforceConsultationTimeLimits extends Command
{
    protected $signature = 'consultations:enforce-time-limits';

    protected $description = 'Interrupt active consultations that exceeded their booked time plus grace.';

    public function handle(ConsultationLifecycle $lifecycle): int
    {
        $this->info($lifecycle->enforceTimeLimits().' consultation(s) interrupted.');

        return self::SUCCESS;
    }
}
