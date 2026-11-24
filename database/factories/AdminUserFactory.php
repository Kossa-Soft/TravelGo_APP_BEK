<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Support\AdminTotp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => 'factory-password',
            'totp_secret' => (new AdminTotp)->generateSecret(),
            'is_superadmin' => false,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(['is_superadmin' => true]);
    }
}
