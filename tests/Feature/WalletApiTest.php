<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Merchant;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey = 'WALLET_TEST_KEY_' . bin2hex(random_bytes(16)),
        ]);

        $this->wallet = Wallet::factory()->create([
            'merchant_id' => $this->merchant->id,
            'user_identifier' => 'user_001',
            'currency' => 'NGN',
            'label' => 'Primary Wallet',
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    public function test_list_wallets(): void
    {
        Wallet::factory()->create([
            'merchant_id' => $this->merchant->id,
            'user_identifier' => 'user_002',
            'currency' => 'USD',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/client/wallets');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_create_wallet(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/client/wallets', [
                'user_identifier' => 'user_new',
                'currency' => 'NGN',
                'label' => 'Savings Wallet',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('wallets', [
            'merchant_id' => $this->merchant->id,
            'user_identifier' => 'user_new',
        ]);
    }

    public function test_get_wallet_balance(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson("api/client/wallets/{$this->wallet->id}/balance");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['balance', 'ledger_balance', 'hold_balance', 'available_balance'],
        ]);
    }

    public function test_credit_wallet(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson("api/client/wallets/{$this->wallet->id}/credit", [
                'amount' => 50000,
                'reference_type' => 'test',
                'reference_id' => 'ref_001',
                'description' => 'Test credit',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $this->wallet->id,
            'type' => 'credit',
            'amount' => 50000,
        ]);
    }

    public function test_debit_wallet_insufficient_balance(): void
    {
        $this->wallet->update(['balance' => 1000, 'ledger_balance' => 1000]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("api/client/wallets/{$this->wallet->id}/debit", [
                'amount' => 50000,
                'reference_type' => 'test',
                'reference_id' => 'ref_002',
                'description' => 'Overdraft test',
            ]);

        $response->assertStatus(500);
    }

    public function test_wallet_transactions_history(): void
    {
        WalletTransaction::factory()->count(5)->create([
            'wallet_id' => $this->wallet->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("api/client/wallets/{$this->wallet->id}/transactions");

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.data'));
    }

    public function test_find_wallet_by_user(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/client/wallets/find-by-user?user_identifier=user_001&currency=NGN');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user_identifier', 'user_001');
    }

    public function test_transfer_between_wallets(): void
    {
        $this->wallet->update(['balance' => 100000, 'ledger_balance' => 100000]);

        $dest = Wallet::factory()->create([
            'merchant_id' => $this->merchant->id,
            'user_identifier' => 'user_dest',
            'currency' => 'NGN',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/client/wallets/transfer', [
                'from_wallet_id' => $this->wallet->id,
                'to_wallet_id' => $dest->id,
                'amount' => 30000,
                'reference_type' => 'transfer',
                'reference_id' => 'ref_transfer_001',
                'description' => 'Transfer test',
            ]);

        $response->assertStatus(200);

        $this->wallet->refresh();
        $dest->refresh();

        $this->assertEquals(70000, (float) $this->wallet->balance);
        $this->assertGreaterThan(0, (float) $dest->balance);
    }
}
