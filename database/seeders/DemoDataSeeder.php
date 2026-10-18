<?php

namespace Database\Seeders;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Specialties\Models\Specialty;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic demo accounts for local development. All share the password
 * "Password123". Privileged accounts use the TOTP secret below - add it to an
 * authenticator app to sign in locally:  JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must never be seeded in production.');
        }

        $patientUser = User::factory()->patient()->create([
            'name' => 'Demo Patient', 'phone' => '+966500000001', 'email' => 'patient@medconnect.local',
        ]);
        Patient::factory()->forUser($patientUser)->create(['date_of_birth' => '1990-05-14', 'gender' => 'female']);

        $doctorUser = User::factory()->doctor()->withMfa()->create([
            'name' => 'Demo Doctor', 'phone' => '+966500000002', 'email' => 'doctor@medconnect.local',
        ]);
        Doctor::factory()->forUser($doctorUser)->approved()
            ->withSpecialty(Specialty::where('slug', 'family-medicine')->firstOrFail())
            ->create(['display_name_en' => 'Dr. Demo Doctor', 'display_name_ar' => 'د. طبيب تجريبي']);

        // Additional listed doctors for search demos.
        Specialty::whereIn('slug', ['dermatology', 'pediatrics', 'psychiatry', 'cardiology'])->get()
            ->each(fn (Specialty $s) => Doctor::factory()->approved()->withSpecialty($s)->create());

        User::factory()->pharmacyStaff()->withMfa()->create([
            'name' => 'Demo Pharmacist', 'phone' => '+966500000003', 'email' => 'pharmacy@medconnect.local',
        ]);

        User::factory()->admin()->withMfa()->create([
            'name' => 'Demo Admin', 'phone' => '+966500000004', 'email' => 'admin@medconnect.local',
        ]);

        User::factory()->superAdmin()->withMfa()->create([
            'name' => 'Demo Super Admin', 'phone' => '+966500000005', 'email' => 'superadmin@medconnect.local',
        ]);
    }
}
