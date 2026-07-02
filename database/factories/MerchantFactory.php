<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'business_name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'password' => Hash::make('password'),
            'phone' => fake()->phoneNumber(),
            'website' => fake()->url(),
            'webhook_url' => fake()->url() . '/webhooks',
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
