<?php

namespace App\Modules\Administration;

use App\Modules\Administration\Services\PlatformSettings;
use Illuminate\Support\ServiceProvider;

class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Operator overrides of allow-listed settings; defaults apply if the table is unavailable.
        PlatformSettings::applyOverrides();
    }
}
