<?php

namespace Database\Factories;

use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 1000000);

        return [
            'wallet_id' => null,
            'type' => fake()->randomElement(['credit', 'debit']),
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => $amount,
            'reference_type' => 'transaction',
            'reference_id' => (string) fake()->randomNumber(6),
            'description' => fake()->sentence(),
        ];
    }
}
