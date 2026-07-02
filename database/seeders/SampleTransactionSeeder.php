<?php

namespace Database\Seeders;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $transactions = [
            [
                'transcode' => 'ESC-' . Carbon::now()->subDays(10)->format('Ymd') . '-001',
                'customer_email' => 'customer@example.com',
                'merchant_email' => 'merchant@example.com',
                'description' => 'Payment for Web Development Services',
                'amount' => 250000.00,
                'currency' => 'NGN',
                'trans_status' => 'Open',
                'payment_status' => 'Paid',
                'payment_gateway' => 'paystack',
                'posting_date' => Carbon::now()->subDays(10),
                'payment_date' => Carbon::now()->subDays(10),
                'startdate' => Carbon::now()->subDays(10)->format('Y-m-d'),
                'enddate' => Carbon::now()->addDays(20)->format('Y-m-d'),
                'fulfill_days' => '30',
                'merchantid' => 1001,
                'appid' => '1001',
                'pepperest_fee' => 3750.00,
            ],
            [
                'transcode' => 'ESC-' . Carbon::now()->subDays(5)->format('Ymd') . '-002',
                'customer_email' => 'alice@example.com',
                'merchant_email' => 'bob@example.com',
                'description' => 'Graphic Design Project',
                'amount' => 85000.00,
                'currency' => 'NGN',
                'trans_status' => 'Pending',
                'payment_status' => null,
                'payment_gateway' => 'stripe',
                'posting_date' => Carbon::now()->subDays(5),
                'startdate' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'enddate' => Carbon::now()->addDays(25)->format('Y-m-d'),
                'fulfill_days' => '30',
                'merchantid' => 1001,
                'appid' => '1001',
                'pepperest_fee' => 1275.00,
            ],
            [
                'transcode' => 'ESC-' . Carbon::now()->subDays(15)->format('Ymd') . '-003',
                'customer_email' => 'charlie@example.com',
                'merchant_email' => 'dave@example.com',
                'description' => 'Consulting Fee - Q1 2026',
                'amount' => 500000.00,
                'currency' => 'NGN',
                'trans_status' => 'Released',
                'payment_status' => 'Paid',
                'payment_gateway' => 'paystack',
                'posting_date' => Carbon::now()->subDays(15),
                'payment_date' => Carbon::now()->subDays(15),
                'releasedate' => Carbon::now()->subDays(3),
                'confirmed_by_merchant' => true,
                'confirmed_date' => Carbon::now()->subDays(4),
                'startdate' => Carbon::now()->subDays(15)->format('Y-m-d'),
                'enddate' => Carbon::now()->subDays(1)->format('Y-m-d'),
                'fulfill_days' => '14',
                'merchantid' => 1002,
                'appid' => '1002',
                'pepperest_fee' => 7500.00,
                'amountpaid' => 500000.00,
            ],
        ];

        foreach ($transactions as $data) {
            Transaction::create($data);
        }
    }
}
