<?php

namespace Database\Factories\Specialties;

use App\Modules\Specialties\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Specialty>
 */
class SpecialtyFactory extends Factory
{
    protected $model = Specialty::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name_en' => $name,
            'name_ar' => 'تخصص '.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
