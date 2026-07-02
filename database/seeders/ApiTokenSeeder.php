<?php

namespace Database\Seeders;

use App\Models\ApiToken;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ApiTokenSeeder extends Seeder
{
    public function run(): void
    {
        ApiToken::create([
            'app_id' => 1001,
            'api_key' => 'ESCROW_TEST_' . Str::random(32),
            'api_secret' => Str::random(32),
            'status' => true,
            'payment_gateway' => 'paystack',
            'gateway_config' => null,
        ]);

        ApiToken::create([
            'app_id' => 1002,
            'api_key' => 'ESCROW_LIVE_' . Str::random(32),
            'api_secret' => Str::random(32),
            'status' => true,
            'payment_gateway' => null,
            'gateway_config' => null,
        ]);
    }
}
