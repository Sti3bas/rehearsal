<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'unused-in-example',
        ];
    }

    public function staff(): static
    {
        return $this->state(['name' => 'Staff']);
    }
}
