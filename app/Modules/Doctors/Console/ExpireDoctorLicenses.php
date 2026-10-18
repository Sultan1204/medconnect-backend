<?php

namespace App\Modules\Doctors\Console;

use App\Modules\Doctors\Services\DoctorVerificationService;
use Illuminate\Console\Command;

class ExpireDoctorLicenses extends Command
{
    protected $signature = 'doctors:expire-licenses';

    protected $description = 'Move approved doctors whose professional licence has lapsed to "expired".';

    public function handle(DoctorVerificationService $verification): int
    {
        $count = $verification->expireLapsedLicenses();
        $this->info("{$count} doctor profile(s) expired.");

        return self::SUCCESS;
    }
}
