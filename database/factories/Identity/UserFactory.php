<?php

namespace Database\Factories\Identity;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic users only - never seed real people's data.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // +9665 followed by 8 digits (synthetic, not real numbers).
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
            'preferred_locale' => 'en',
        ];
    }

    public function unverified(): static
    {
        return $this->state(['phone_verified_at' => null, 'email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => UserStatus::Suspended, 'status_changed_at' => now()]);
    }

    public function withMfa(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): static
    {
        return $this->state(['mfa_secret' => $secret, 'mfa_enabled_at' => now()]);
    }

    public function role(RoleName $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    public function patient(): static
    {
        return $this->role(RoleName::Patient);
    }

    public function doctor(): static
    {
        return $this->role(RoleName::Doctor);
    }

    public function admin(): static
    {
        return $this->role(RoleName::Admin);
    }

    public function superAdmin(): static
    {
        return $this->role(RoleName::SuperAdmin);
    }

    public function pharmacyStaff(): static
    {
        return $this->role(RoleName::PharmacyStaff);
    }
}
