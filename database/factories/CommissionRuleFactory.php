<?php

namespace Database\Factories;

use App\Models\CommissionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'merchant_id' => null,
            'transaction_type_id' => null,
            'name' => fake()->words(3, true),
            'rate_type' => 'percentage',
            'rate_value' => 2.5,
            'cap_amount' => null,
            'min_amount' => null,
            'max_amount' => null,
            'priority' => 0,
            'payer' => 'merchant',
            'status' => true,
        ];
    }

    public function flat(): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_type' => 'flat',
            'rate_value' => 500,
        ]);
    }

    public function withCap(float $cap): static
    {
        return $this->state(fn (array $attributes) => [
            'cap_amount' => $cap,
        ]);
    }
}
