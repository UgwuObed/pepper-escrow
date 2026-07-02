<?php

namespace Database\Factories;

use App\Models\Settlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SettlementFactory extends Factory
{
    protected $model = Settlement::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 50000, 5000000);

        return [
            'merchant_id' => null,
            'batch_number' => 'STL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'status' => fake()->randomElement(['pending', 'completed', 'failed']),
            'total_amount' => $total,
            'total_commission' => round($total * 0.025, 2),
            'net_amount' => round($total * 0.975, 2),
            'item_count' => fake()->numberBetween(1, 20),
            'currency' => 'NGN',
            'payment_gateway' => 'paystack',
        ];
    }
}
