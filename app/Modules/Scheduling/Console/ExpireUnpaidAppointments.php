<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Actions\TransitionAppointment;
use Illuminate\Console\Command;

class ExpireUnpaidAppointments extends Command
{
    protected $signature = 'appointments:expire-unpaid';

    protected $description = 'Release appointment slots whose payment window has elapsed.';

    public function handle(TransitionAppointment $transitions): int
    {
        $this->info($transitions->expireUnpaid().' appointment(s) expired.');

        return self::SUCCESS;
    }
}
