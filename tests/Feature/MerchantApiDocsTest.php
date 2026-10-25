<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantApiDocsTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create(['business_name' => 'Acme Marketplace']);

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => 'KEY_' . bin2hex(random_bytes(10)),
        ]);
    }

    private function asMerchant()
    {
        return $this->actingAs($this->merchant, 'merchant');
    }

    public function test_the_docs_require_a_logged_in_merchant(): void
    {
        $this->get('/merchant/api-docs')->assertRedirect();
    }

    public function test_the_docs_render_with_the_merchants_own_details(): void
    {
        $this->asMerchant()->get('/merchant/api-docs')
            ->assertStatus(200)
            ->assertSee('API Documentation')
            ->assertSee(url('/api/holds'))
            ->assertSee($this->merchant->apiToken->api_key);
    }

    public function test_the_seller_link_appears_only_once_switched_on(): void
    {
        $this->asMerchant()->get('/merchant/api-docs')
            ->assertStatus(200)
            ->assertDontSee('seller sign-up link');

        $this->merchant->sellerOnboardingToken();
        $this->merchant->forceFill(['seller_onboarding_enabled' => true])->save();

        $this->asMerchant()->get('/merchant/api-docs')
            ->assertStatus(200)
            ->assertSee('Your seller sign-up link');
    }

    public function test_the_postman_collection_downloads(): void
    {
        $this->asMerchant()->get('/merchant/api-docs/postman')
            ->assertStatus(200)
            ->assertHeader('content-disposition', 'attachment; filename=Peppa-Settlements.postman_collection.json');
    }

    /**
     * The environment carries this merchant's base URL but never a key: a
     * file with a key in it is a file that gets forwarded.
     */
    public function test_the_environment_download_carries_no_key(): void
    {
        $response = $this->asMerchant()->get('/merchant/api-docs/postman-env')->assertStatus(200);

        $env = $response->json();
        $values = collect($env['values'])->keyBy('key');

        $this->assertSame(url('/'), $values['base_url']['value']);
        $this->assertSame('', $values['api_key']['value']);
        $this->assertStringContainsString('Acme Marketplace', $env['name']);
    }

    public function test_another_merchant_sees_their_own_key_not_this_one(): void
    {
        $other = Merchant::factory()->create();
        ApiToken::factory()->create([
            'app_id' => $other->id, 'merchant_id' => $other->id, 'api_key' => 'OTHER_KEY_123',
        ]);

        $this->actingAs($other, 'merchant')->get('/merchant/api-docs')
            ->assertStatus(200)
            ->assertSee('OTHER_KEY_123')
            ->assertDontSee($this->merchant->apiToken->api_key);
    }
}
