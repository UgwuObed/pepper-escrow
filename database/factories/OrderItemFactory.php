<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 10);
        $unitPrice = fake()->randomFloat(2, 500, 500000);

        return [
            'order_id' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => round($qty * $unitPrice, 2),
        ];
    }
}
