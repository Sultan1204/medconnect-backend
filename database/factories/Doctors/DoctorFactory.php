<?php

namespace Database\Factories\Doctors;

use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Specialties\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic doctors only - licence numbers are random and not real.
 *
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->doctor()->withMfa(),
            'display_name_en' => 'Dr. '.fake()->name(),
            'display_name_ar' => 'د. '.fake()->firstName(),
            'title' => 'Dr.',
            'gender' => fake()->randomElement(['male', 'female']),
            'bio_en' => fake()->sentence(12),
            'years_of_experience' => fake()->numberBetween(1, 30),
            'languages' => ['ar', 'en'],
            'professional_rank' => 'specialist',
            'license_number' => 'TEST-'.fake()->unique()->numerify('########'),
            'licensing_authority' => 'SCFHS',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'video_fee_amount' => 15000,
            'chat_fee_amount' => 8000,
        ];
    }

    /** Verified, listed and bookable. */
    public function approved(): static
    {
        return $this->state(fn () => [
            'verification_status' => VerificationStatus::Approved,
            'verification_status_changed_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function status(VerificationStatus $status): static
    {
        return $this->state(['verification_status' => $status, 'verification_status_changed_at' => now()]);
    }

    public function withSpecialty(Specialty $specialty): static
    {
        return $this->afterCreating(fn (Doctor $d) => $d->specialties()->attach($specialty->id, ['is_primary' => true]));
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id, 'display_name_en' => $user->name]);
    }
}
