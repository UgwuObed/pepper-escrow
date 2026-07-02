<?php

namespace Database\Factories;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        return [
            'app_id' => null,
            'merchant_id' => null,
            'api_key' => 'ESCROW_TEST_' . Str::random(32),
            'api_secret' => Str::random(48),
            'status' => true,
            'payment_gateway' => 'paystack',
            'gateway_config' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
