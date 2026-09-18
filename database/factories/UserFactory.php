<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::DRIVER,
            'is_active' => true,
        ];
    }

    public function instructor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::INSTRUCTOR,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ADMIN,
        ]);
    }

    public function driver(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::DRIVER,
        ]);
    }

    public function dispatcher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::DISPATCHER,
        ]);
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::STUDENT,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SUPER_ADMIN,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
