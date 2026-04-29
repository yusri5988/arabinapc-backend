<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'expense',
            'amount' => $this->faker->randomFloat(2, 5, 500),
            'description' => $this->faker->sentence(3),
            'site_id' => strtoupper($this->faker->lexify(1)) . $this->faker->numberBetween(100, 999),
            'receipt_url' => null,
            'date' => $this->faker->date(),
        ];
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
        ]);
    }

    public function topup(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'topup',
        ]);
    }

    public function withReceipt(string $url = '/storage/receipts/test.jpg'): static
    {
        return $this->state(fn (array $attributes) => [
            'receipt_url' => $url,
        ]);
    }
}
