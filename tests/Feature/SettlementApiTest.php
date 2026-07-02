<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey = 'STL_TEST_KEY_' . bin2hex(random_bytes(16)),
        ]);

        // Create transactions with Closed status for settlement
        for ($i = 0; $i < 5; $i++) {
            Transaction::factory()->create([
                'appid' => (string) $this->merchant->id,
                'merchantid' => $this->merchant->id,
                'merchant_email' => $this->merchant->email,
                'trans_status' => 'Closed',
                'payment_status' => 'Paid',
                'payment_date' => Carbon::now()->subDays(10),
                'releasedate' => Carbon::now()->subDays(5),
                'confirmed_by_merchant' => true,
                'confirmed_date' => Carbon::now()->subDays(6),
                'settled_at' => null,
            ]);
        }
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    public function test_list_settlements(): void
    {
        Settlement::factory()->count(3)->create([
            'merchant_id' => $this->merchant->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/client/settlements');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_create_settlement(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/client/settlements', [
                'auto_process' => false,
            ]);

        $response->assertStatus(201);
    }

    public function test_show_settlement(): void
    {
        $settlement = Settlement::factory()->create([
            'merchant_id' => $this->merchant->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("api/client/settlements/{$settlement->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $settlement->id);
    }
}
