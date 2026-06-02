<?php

namespace Database\Factories;

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
            'phone' => fake()->unique()->numerify('01########'),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'supervisor',
            'department' => null,
            'balance' => 0,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'department' => null,
        ]);
    }

    public function supervisor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'supervisor',
            'department' => 'Site',
        ]);
    }

    public function siteDepartment(): static
    {
        return $this->state(fn (array $attributes) => [
            'department' => 'Site',
        ]);
    }

    public function humanResourceDepartment(): static
    {
        return $this->state(fn (array $attributes) => [
            'department' => 'Human Resource',
        ]);
    }

    public function salesManagerDepartment(): static
    {
        return $this->state(fn (array $attributes) => [
            'department' => 'Sales Manager',
        ]);
    }

    public function developer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'developer',
            'department' => null,
        ]);
    }

    public function withBalance(float $balance): static
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $balance,
        ]);
    }
}
