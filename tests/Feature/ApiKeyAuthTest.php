<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_api_key(): void
    {
        $response = $this->postJson('api/escrow/Transaction/create', []);

        $response->assertStatus(401);
        $response->assertJson(['ResponseCode' => 401]);
    }

    public function test_returns_401_with_invalid_api_key(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid_key'])
            ->postJson('api/escrow/Transaction/create', []);

        $response->assertStatus(401);
    }

    public function test_returns_401_with_inactive_api_key(): void
    {
        $merchant = Merchant::factory()->create();
        ApiToken::factory()->inactive()->create([
            'app_id' => $merchant->id,
            'merchant_id' => $merchant->id,
        ]);

        $token = ApiToken::where('app_id', $merchant->id)->first();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token->api_key])
            ->postJson('api/escrow/Transaction/create', []);

        $response->assertStatus(401);
    }

    public function test_passes_with_valid_api_key_in_header(): void
    {
        $merchant = Merchant::factory()->create();
        ApiToken::factory()->create([
            'app_id' => $merchant->id,
            'merchant_id' => $merchant->id,
        ]);

        $token = ApiToken::where('app_id', $merchant->id)->first();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token->api_key])
            ->postJson('api/escrow/Transaction/create', []);

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_passes_with_valid_api_key_in_body(): void
    {
        $merchant = Merchant::factory()->create();
        ApiToken::factory()->create([
            'app_id' => $merchant->id,
            'merchant_id' => $merchant->id,
        ]);

        $token = ApiToken::where('app_id', $merchant->id)->first();

        $response = $this->postJson('api/escrow/Transaction/create', [
            'api_key' => $token->api_key,
        ]);

        $this->assertNotEquals(401, $response->getStatusCode());
    }
}
