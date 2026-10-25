<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;
    private ConnectedAccount $account;
    private DashboardFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'SELLER_' . bin2hex(random_bytes(12));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
        ]);

        $this->account = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_dash',
            'seller_email' => 'seller@example.com',
            'country' => 'GB',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'payout_schedule' => 'manual',
            'losses_payer' => 'stripe',
            'dashboard_type' => 'none',
            'holding_period_days' => 90,
            'requirements_due' => [],
        ]);

        $this->gateway = new DashboardFakeGateway();
        $this->app->singleton(EscrowPaymentService::class, function () {
            $s = new EscrowPaymentService();
            $s->registerGateway('stripe_connect', $this->gateway);

            return $s;
        });
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    private function hold(string $ref, float $amount, array $extra = []): SettlementHold
    {
        return SettlementHold::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'connected_account_id' => $this->account->id,
            'reference' => $ref,
            'amount' => $amount,
            'currency' => 'GBP',
            'status' => SettlementHold::STATUS_HELD,
            'held_at' => now()->subDays(2),
            'hold_expires_at' => now()->addDays(88),
        ], $extra));
    }

    private function dashboard()
    {
        return $this->withHeaders($this->auth())
            ->getJson("api/connected-accounts/{$this->account->id}/dashboard");
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->getJson("api/connected-accounts/{$this->account->id}/dashboard")->assertStatus(401);
    }

    public function test_it_reports_held_and_released_totals(): void
    {
        $this->hold('A', 100.0);
        $this->hold('B', 50.0, ['amount_released' => 50.0, 'status' => SettlementHold::STATUS_RELEASED]);

        $response = $this->dashboard()->assertStatus(200);

        $this->assertEquals(100.0, $response->json('data.holds.currently_held'));
        $this->assertEquals(50.0, $response->json('data.holds.released_to_date'));
    }

    /**
     * A seller chasing a payment needs to be told where it is, not shown a
     * status code.
     */
    public function test_each_hold_is_explained_in_words(): void
    {
        $this->hold('A', 100.0);
        $this->hold('B', 50.0, ['status' => SettlementHold::STATUS_DISPUTED]);
        $this->hold('C', 25.0, ['amount_released' => 25.0, 'status' => SettlementHold::STATUS_RELEASED]);

        $items = collect($this->dashboard()->json('data.holds.items'))->keyBy('reference');

        $this->assertStringContainsString('Held until', $items['A']['explanation']);
        $this->assertStringContainsString('dispute', $items['B']['explanation']);
        $this->assertStringContainsString('Paid out', $items['C']['explanation']);
    }

    public function test_balance_is_converted_to_major_units(): void
    {
        $this->gateway->balance = ['available' => ['GBP' => 9500], 'pending' => ['GBP' => 0]];

        $this->dashboard()->assertStatus(200)
            ->assertJsonPath('data.balance.available.GBP', 95)
            ->assertJsonPath('data.balance.source', 'stripe');
    }

    /**
     * The held-funds view comes from our own records, so Stripe being down
     * must not blank the page a seller opened to chase a payment.
     */
    public function test_stripe_being_down_degrades_rather_than_fails(): void
    {
        $this->hold('A', 100.0);
        $this->gateway->throw = true;

        $response = $this->dashboard()->assertStatus(200);

        $this->assertEquals(100.0, $response->json('data.holds.currently_held'));
        $this->assertSame('unavailable', $response->json('data.balance.source'));
        $this->assertNull($response->json('data.balance.available'));
        $this->assertSame([], $response->json('data.payouts'));
    }

    public function test_onboarding_state_is_described_plainly(): void
    {
        $this->dashboard()->assertStatus(200)
            ->assertJsonPath('data.onboarding.state', 'complete')
            ->assertJsonPath('data.onboarding.can_be_paid_out', true);

        $this->account->update([
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements_due' => ['individual.dob.day'],
        ]);

        $response = $this->dashboard()->assertStatus(200)
            ->assertJsonPath('data.onboarding.state', 'incomplete');

        $this->assertContains('individual.dob.day', $response->json('data.onboarding.outstanding_requirements'));
    }

    public function test_a_submitted_account_awaiting_stripe_says_nothing_is_needed(): void
    {
        $this->account->update(['charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => true]);

        $this->dashboard()->assertStatus(200)
            ->assertJsonPath('data.onboarding.state', 'in_review')
            ->assertJsonPath('data.onboarding.message', 'Stripe is reviewing your details. Nothing is needed from you right now.');
    }

    public function test_payout_history_is_listed(): void
    {
        $this->gateway->payoutList = [
            ['id' => 'po_1', 'amount' => 95.0, 'currency' => 'GBP', 'status' => 'paid',
             'arrival_date' => time(), 'created' => time(), 'metadata' => ['reference' => 'A']],
        ];

        $this->dashboard()->assertStatus(200)
            ->assertJsonPath('data.payouts.0.id', 'po_1')
            ->assertJsonPath('data.payouts.0.reference', 'A');
    }

    public function test_another_merchants_seller_is_not_visible(): void
    {
        $other = Merchant::factory()->create();
        $otherKey = 'OTHER_' . bin2hex(random_bytes(8));
        ApiToken::factory()->create(['app_id' => $other->id, 'merchant_id' => $other->id, 'api_key' => $otherKey]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $otherKey])
            ->getJson("api/connected-accounts/{$this->account->id}/dashboard")
            ->assertStatus(404);
    }

    // ─── Embedded components ──────────────────────────────────────────

    public function test_an_account_session_is_issued(): void
    {
        $this->withHeaders($this->auth())
            ->postJson("api/connected-accounts/{$this->account->id}/account-session")
            ->assertStatus(200)
            ->assertJsonPath('data.client_secret', 'acct_sess_secret');
    }

    public function test_an_empty_component_list_is_rejected(): void
    {
        $this->withHeaders($this->auth())
            ->postJson("api/connected-accounts/{$this->account->id}/account-session", ['components' => []])
            ->assertStatus(422);
    }
}

class DashboardFakeGateway extends StripeConnectGateway
{
    public array $balance = ['available' => ['GBP' => 0], 'pending' => ['GBP' => 0]];
    public array $payoutList = [];
    public bool $throw = false;

    public function __construct()
    {
        parent::__construct(['secret_key' => 'sk_test_fake']);
    }

    public function getBalance(string $accountId): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Stripe unreachable');
        }

        return $this->balance;
    }

    public function listPayouts(string $accountId, int $pageSize = 100): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Stripe unreachable');
        }

        return $this->payoutList;
    }

    public function createAccountSession(string $accountId, array $components = ['payouts', 'account_management']): array
    {
        return [
            'client_secret' => 'acct_sess_secret',
            'expires_at' => time() + 3600,
            'components' => $components,
        ];
    }
}
