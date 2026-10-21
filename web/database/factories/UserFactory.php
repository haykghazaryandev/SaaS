<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'phone' => '+3749'.fake()->unique()->numerify('#######'),
            'name' => fake()->firstName().' '.fake()->lastName(),
            'locale' => 'en',
            'phone_verified_at' => now(),
            'registered_at' => now(),
        ];
    }

    public function guest(): static
    {
        return $this->state(['registered_at' => null, 'name' => null]);
    }
}
