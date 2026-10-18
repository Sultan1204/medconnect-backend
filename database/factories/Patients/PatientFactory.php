<?php

namespace Database\Factories\Patients;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientGuardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic patients only.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->patient(),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-19 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id, 'full_name' => $user->name]);
    }

    /** A child without their own account. */
    public function dependentOf(User $guardian, GuardianStatus $status = GuardianStatus::Active): static
    {
        return $this->state([
            'user_id' => null,
            'created_by_user_id' => $guardian->id,
            'date_of_birth' => fake()->dateTimeBetween('-12 years', '-2 years')->format('Y-m-d'),
        ])->afterCreating(fn (Patient $patient) => PatientGuardian::create([
            'patient_id' => $patient->id,
            'guardian_user_id' => $guardian->id,
            'relationship' => 'parent',
            'status' => $status,
        ]));
    }

    public function minor(): static
    {
        return $this->state(['date_of_birth' => now()->subYears(15)->format('Y-m-d')]);
    }
}
