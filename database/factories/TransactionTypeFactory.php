<?php

namespace Database\Factories;

use App\Models\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionTypeFactory extends Factory
{
    protected $model = TransactionType::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'merchant_id' => null,
            'name' => ucfirst($name),
            'slug' => $name,
            'description' => fake()->sentence(),
            'supports_escrow' => true,
            'requires_fulfillment' => true,
            'status' => true,
        ];
    }

    public function directSale(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Direct Sale',
            'slug' => 'direct_sale',
            'supports_escrow' => false,
            'requires_fulfillment' => false,
        ]);
    }
}
