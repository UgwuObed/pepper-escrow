<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementHoldApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;
    private ConnectedAccount $account;
    private FakeConnectGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'HOLD_TEST_' . bin2hex(random_bytes(12));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
        ]);

        $this->account = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_api',
            'seller_email' => 'seller@example.com',
            'country' => 'GB',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'payout_schedule' => 'manual',
            'losses_payer' => 'stripe',
            'dashboard_type' => 'none',
            'holding_period_days' => 90,
        ]);

        $this->gateway = new FakeConnectGateway();
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

    private function openHold(string $ref = 'ORDER-1', float $amount = 100.0)
    {
        return $this->withHeaders($this->auth())->postJson('api/holds', [
            'reference' => $ref,
            'connected_account_id' => 'acct_api',
            'amount' => $amount,
            'currency' => 'GBP',
            'payment_intent_id' => 'pi_test',
        ]);
    }

    private function rule(string $type, array $extra = []): SettlementRule
    {
        return SettlementRule::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'name' => $type,
            'condition_type' => $type,
            'config' => [],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ], $extra));
    }

    // ─── Auth ─────────────────────────────────────────────────────────

    public function test_the_api_requires_a_key(): void
    {
        $this->postJson('api/holds', ['reference' => 'X'])->assertStatus(401);
        $this->getJson('api/holds')->assertStatus(401);
    }

    // ─── Opening holds ────────────────────────────────────────────────

    public function test_a_hold_can_be_opened(): void
    {
        $response = $this->openHold();

        $response->assertStatus(201)
            ->assertJsonPath('data.reference', 'ORDER-1')
            ->assertJsonPath('data.status', 'held');

        // Compared loosely: PHP serialises 100.0 to JSON as 100.
        $this->assertEquals(100.0, $response->json('data.remaining'));
        $this->assertNotNull($response->json('data.release_by'));
    }

    /**
     * A retried call must not open a second hold against one payment.
     */
    public function test_opening_the_same_reference_twice_is_idempotent(): void
    {
        $this->openHold('ORDER-DUP')->assertStatus(201);
        $this->openHold('ORDER-DUP')->assertStatus(200);

        $this->assertSame(1, SettlementHold::where('reference', 'ORDER-DUP')->count());
    }

    public function test_a_seller_that_cannot_hold_is_refused_with_reasons(): void
    {
        $this->account->update(['payout_schedule' => 'daily']);

        $this->openHold()
            ->assertStatus(409)
            ->assertJsonStructure(['blockers']);
    }

    public function test_an_unknown_seller_account_is_rejected(): void
    {
        $this->withHeaders($this->auth())->postJson('api/holds', [
            'reference' => 'X', 'connected_account_id' => 'acct_nope', 'amount' => 10,
        ])->assertStatus(404);
    }

    public function test_another_merchants_hold_is_not_visible(): void
    {
        $this->openHold('ORDER-MINE');

        $other = Merchant::factory()->create();
        $otherKey = 'OTHER_' . bin2hex(random_bytes(8));
        ApiToken::factory()->create([
            'app_id' => $other->id, 'merchant_id' => $other->id, 'api_key' => $otherKey,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $otherKey])
            ->getJson('api/holds/ORDER-MINE')
            ->assertStatus(404);
    }

    // ─── Conditions ───────────────────────────────────────────────────

    public function test_buyer_confirmation_releases_the_money(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $this->openHold('ORDER-C');

        $response = $this->withHeaders($this->auth())->postJson('api/holds/ORDER-C/confirm')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'released');

        $this->assertEquals(0.0, $response->json('data.remaining'));
        $this->assertCount(1, $this->gateway->payouts);
    }

    public function test_manual_approval_records_who_approved(): void
    {
        $this->rule(SettlementRule::CONDITION_MANUAL_APPROVAL);
        $this->openHold('ORDER-A');

        $response = $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-A/approve', ['actor' => 'admin:42']);

        $response->assertStatus(200)->assertJsonPath('data.status', 'released');

        $actors = collect($response->json('data.events'))->pluck('actor');
        $this->assertTrue($actors->contains('admin:42'));
    }

    public function test_a_dispute_freezes_the_hold(): void
    {
        $this->openHold('ORDER-D');

        $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-D/dispute', ['reason' => 'never arrived'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disputed')
            ->assertJsonPath('data.is_disputed', true);
    }

    public function test_a_dispute_requires_a_reason(): void
    {
        $this->openHold('ORDER-D2');

        $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-D2/dispute', [])
            ->assertStatus(422);
    }

    public function test_confirming_a_disputed_hold_does_not_release(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $this->openHold('ORDER-D3');
        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-D3/dispute', ['reason' => 'x']);

        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-D3/confirm')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disputed');

        $this->assertCount(0, $this->gateway->payouts);
    }

    public function test_resolving_in_the_sellers_favour_releases(): void
    {
        $this->openHold('ORDER-R');
        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-R/dispute', ['reason' => 'x']);

        $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-R/dispute/resolve', ['outcome' => 'release', 'actor' => 'admin:1'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'released');
    }

    public function test_resolving_in_the_buyers_favour_refunds(): void
    {
        $this->openHold('ORDER-R2');
        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-R2/dispute', ['reason' => 'x']);

        $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-R2/dispute/resolve', ['outcome' => 'refund', 'actor' => 'admin:1'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'refunded');

        $this->assertCount(1, $this->gateway->refunds);
    }

    /**
     * Resolving something that is not disputed is a state conflict, not a
     * server error.
     */
    public function test_resolving_a_hold_that_is_not_disputed_is_a_conflict(): void
    {
        $this->openHold('ORDER-N');

        $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-N/dispute/resolve', ['outcome' => 'release', 'actor' => 'admin:1'])
            ->assertStatus(409);
    }

    public function test_a_partial_refund_leaves_the_rest_held(): void
    {
        $this->openHold('ORDER-PR', 100.0);

        $response = $this->withHeaders($this->auth())
            ->postJson('api/holds/ORDER-PR/refund', ['amount' => 30, 'reason' => 'partial'])
            ->assertStatus(200);

        $this->assertEquals(30.0, $response->json('data.amount_refunded'));
        $this->assertEquals(70.0, $response->json('data.remaining'));
    }

    // ─── Listing ──────────────────────────────────────────────────────

    public function test_holds_can_be_listed_and_filtered(): void
    {
        $this->openHold('ORDER-L1');
        $this->openHold('ORDER-L2');
        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-L2/dispute', ['reason' => 'x']);

        $this->withHeaders($this->auth())->getJson('api/holds')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);

        $this->withHeaders($this->auth())->getJson('api/holds?status=disputed')
            ->assertStatus(200)->assertJsonPath('meta.total', 1);
    }

    public function test_show_includes_the_event_trail(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $this->openHold('ORDER-E');
        $this->withHeaders($this->auth())->postJson('api/holds/ORDER-E/confirm');

        $events = $this->withHeaders($this->auth())->getJson('api/holds/ORDER-E')
            ->assertStatus(200)->json('data.events');

        $this->assertSame(['held', 'buyer_confirmed', 'released'], array_column($events, 'event'));
    }

    // ─── Rules ────────────────────────────────────────────────────────

    public function test_a_partner_can_create_and_list_rules(): void
    {
        $this->withHeaders($this->auth())->postJson('api/settlement-rules', [
            'name' => 'Release after a week',
            'condition_type' => 'elapsed_days',
            'days' => 7,
        ])->assertStatus(201)->assertJsonPath('data.days', 7);

        $this->withHeaders($this->auth())->getJson('api/settlement-rules')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_an_elapsed_days_rule_requires_days(): void
    {
        $this->withHeaders($this->auth())->postJson('api/settlement-rules', [
            'name' => 'Broken', 'condition_type' => 'elapsed_days',
        ])->assertStatus(422);
    }

    /**
     * A timer longer than Stripe will hold is a rule that can never fire.
     */
    public function test_a_timer_beyond_the_stripe_limit_is_refused(): void
    {
        $this->withHeaders($this->auth())->postJson('api/settlement-rules', [
            'name' => 'Too long', 'condition_type' => 'elapsed_days', 'days' => 120,
        ])->assertStatus(422);
    }

    public function test_an_unknown_condition_type_is_refused(): void
    {
        $this->withHeaders($this->auth())->postJson('api/settlement-rules', [
            'name' => 'Nope', 'condition_type' => 'when_i_feel_like_it',
        ])->assertStatus(422);
    }

    /**
     * Deleting deactivates, so released holds keep the explanation of why
     * they released.
     */
    public function test_deleting_a_rule_deactivates_it(): void
    {
        $rule = $this->rule(SettlementRule::CONDITION_MANUAL_APPROVAL);

        $this->withHeaders($this->auth())->deleteJson("api/settlement-rules/{$rule->id}")
            ->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('settlement_rules', ['id' => $rule->id, 'is_active' => false]);
    }
}
