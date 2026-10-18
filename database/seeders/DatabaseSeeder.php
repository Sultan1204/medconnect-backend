<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data required in every environment.
     * Demo data (synthetic only) lives in DemoDataSeeder and is never run in production.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SpecialtySeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
