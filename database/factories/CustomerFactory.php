<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'usertype' => 'customer',
            'country' => 'Nigeria',
        ];
    }

    public function merchant(): static
    {
        return $this->state(fn (array $attributes) => [
            'usertype' => 'merchant',
        ]);
    }
}
