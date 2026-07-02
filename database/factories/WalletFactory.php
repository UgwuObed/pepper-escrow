<?php

namespace Database\Factories;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        $ledger = fake()->randomFloat(2, 10000, 5000000);

        return [
            'merchant_id' => null,
            'user_identifier' => fake()->uuid(),
            'currency' => 'NGN',
            'balance' => $ledger,
            'ledger_balance' => $ledger,
            'hold_balance' => 0,
            'type' => 'fiat',
            'label' => 'Main Wallet',
            'status' => true,
        ];
    }

    public function withHold(float $hold): static
    {
        return $this->state(fn (array $attributes) => [
            'hold_balance' => $hold,
            'balance' => $attributes['ledger_balance'] - $hold,
        ]);
    }
}
