<?php

namespace Database\Seeders;

use App\Modules\Specialties\Models\Specialty;
use Illuminate\Database\Seeder;

/**
 * Reference catalogue (all environments). Idempotent by slug; existing
 * specialties edited by administrators are not overwritten.
 */
class SpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            ['family-medicine', 'Family Medicine', 'طب الأسرة'],
            ['internal-medicine', 'Internal Medicine', 'الطب الباطني'],
            ['dermatology', 'Dermatology', 'الأمراض الجلدية'],
            ['orthopedics', 'Orthopedics', 'جراحة العظام'],
            ['ent', 'Ear, Nose and Throat (ENT)', 'الأنف والأذن والحنجرة'],
            ['pediatrics', 'Pediatrics', 'طب الأطفال'],
            ['psychiatry', 'Psychiatry', 'الطب النفسي'],
            ['cardiology', 'Cardiology', 'أمراض القلب'],
            ['neurology', 'Neurology', 'الأمراض العصبية'],
            ['gastroenterology', 'Gastroenterology', 'أمراض الجهاز الهضمي'],
        ];

        foreach ($specialties as $i => [$slug, $en, $ar]) {
            Specialty::firstOrCreate(['slug' => $slug], ['name_en' => $en, 'name_ar' => $ar, 'sort_order' => ($i + 1) * 10]);
        }
    }
}
