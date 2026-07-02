<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 5000000);
        $fee = round($amount * 0.025, 2);

        return [
            'posting_date' => Carbon::now()->subDays(rand(1, 30)),
            'transcode' => 'TXN-' . Carbon::now()->format('Ymd') . '-' . Str::random(6),
            'customer_email' => fake()->safeEmail(),
            'merchant_email' => fake()->companyEmail(),
            'merchantid' => null,
            'description' => fake()->sentence(),
            'amount' => $amount,
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'startdate' => Carbon::now()->subDays(14)->format('Y-m-d'),
            'enddate' => Carbon::now()->addDays(14)->format('Y-m-d'),
            'fulfill_days' => '28 days',
            'payment_gateway' => 'paystack',
            'payment_status' => 'Paid',
            'trans_status' => 'Open',
            'pepperest_fee' => $fee,
            'appid' => null,
            'commission_amount' => $fee,
            'net_amount' => $amount - $fee,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => null,
            'trans_status' => 'Pending',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'Paid',
            'payment_date' => Carbon::now(),
            'trans_status' => 'Open',
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'Paid',
            'payment_date' => Carbon::now()->subDays(5),
            'releasedate' => Carbon::now(),
            'trans_status' => 'Released',
            'confirmed_by_merchant' => true,
            'confirmed_date' => Carbon::now()->subDays(1),
        ]);
    }

    public function withMerchant(Merchant $merchant): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_email' => $merchant->email,
            'merchantid' => $merchant->id,
            'appid' => (string) $merchant->id,
        ]);
    }
}
