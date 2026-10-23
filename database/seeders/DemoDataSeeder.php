<?php

namespace Database\Seeders;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Scheduling\Models\AvailabilityRule;
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
        $demoDoctor = Doctor::factory()->forUser($doctorUser)->approved()
            ->withSpecialty(Specialty::where('slug', 'family-medicine')->firstOrFail())
            ->create(['display_name_en' => 'Dr. Demo Doctor', 'display_name_ar' => 'د. طبيب تجريبي']);
        $this->openWeeklyHours($demoDoctor);

        // Additional listed doctors for search demos.
        Specialty::whereIn('slug', ['dermatology', 'pediatrics', 'psychiatry', 'cardiology'])->get()
            ->each(fn (Specialty $s) => $this->openWeeklyHours(Doctor::factory()->approved()->withSpecialty($s)->create()));

        $pharmacist = User::factory()->pharmacyStaff()->withMfa()->create([
            'name' => 'Demo Pharmacist', 'phone' => '+966500000003', 'email' => 'pharmacy@medconnect.local',
        ]);
        $this->seedPharmacy($pharmacist);

        User::factory()->admin()->withMfa()->create([
            'name' => 'Demo Admin', 'phone' => '+966500000004', 'email' => 'admin@medconnect.local',
        ]);

        User::factory()->superAdmin()->withMfa()->create([
            'name' => 'Demo Super Admin', 'phone' => '+966500000005', 'email' => 'superadmin@medconnect.local',
        ]);
    }

    /** One verified demo pharmacy with two branches; the demo pharmacist manages it. */
    private function seedPharmacy(User $pharmacist): void
    {
        $pharmacy = new Pharmacy([
            'name_en' => 'Demo Pharmacy', 'name_ar' => 'صيدلية تجريبية', 'license_number' => 'DEMO-PH-0001',
            'licensing_authority' => 'SFDA', 'license_expires_on' => now()->addYears(2)->toDateString(),
            'contact_phone' => '+966500000010', 'contact_email' => 'pharmacy-demo@medconnect.local',
        ]);
        $pharmacy->forceFill(['verification_status' => 'approved', 'verified_at' => now()])->save();

        $branches = collect([
            ['Demo Pharmacy - Olaya', 'صيدلية تجريبية - العليا', 'Riyadh', 'Olaya', 'Olaya Street (demo address)'],
            ['Demo Pharmacy - Jeddah', 'صيدلية تجريبية - جدة', 'Jeddah', 'Al Hamra', 'Al Hamra Street (demo address)'],
        ])->map(fn (array $b) => PharmacyBranch::create([
            'pharmacy_id' => $pharmacy->id, 'name_en' => $b[0], 'name_ar' => $b[1], 'city' => $b[2],
            'district' => $b[3], 'address_line' => $b[4], 'phone' => '+966500000011',
            'is_active' => true, 'accepts_eprescriptions' => true,
        ]));

        PharmacyStaff::create([
            'pharmacy_id' => $pharmacy->id, 'branch_id' => null, 'user_id' => $pharmacist->id,
            'role' => 'manager', 'is_active' => true,
        ]);
    }

    /**
     * Sunday-Thursday, two sessions a day, so the booking screens show slots.
     * Local times (Asia/Riyadh); the API returns UTC instants.
     */
    private function openWeeklyHours(Doctor $doctor): void
    {
        foreach ([0, 1, 2, 3, 4] as $day) {
            foreach ([['09:00', '13:00'], ['17:00', '21:00']] as [$start, $end]) {
                AvailabilityRule::create([
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'slot_minutes' => 30,
                    'consultation_types' => ['video', 'chat'],
                ]);
            }
        }
    }
}
