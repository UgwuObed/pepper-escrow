<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use App\Services\SettlementRulesEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementRulesEngineTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private ConnectedAccount $account;
    private SettlementRulesEngine $engine;
    private FakeConnectGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        $this->account = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_test_seller',
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

        $payments = new EscrowPaymentService();
        $payments->registerGateway('stripe_connect', $this->gateway);

        $this->engine = new SettlementRulesEngine($payments);
    }

    private function rule(string $type, array $overrides = []): SettlementRule
    {
        return SettlementRule::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'name' => $type . ' rule',
            'condition_type' => $type,
            'config' => [],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function hold(float $amount = 100.0, string $ref = 'REF-1'): SettlementHold
    {
        return $this->engine->hold($this->account, $ref, $amount, 'GBP', 'pi_test_1');
    }

    // ─── Opening a hold ───────────────────────────────────────────────

    public function test_a_hold_records_the_stripe_deadline_for_the_seller_country(): void
    {
        $hold = $this->hold();

        $this->assertSame(SettlementHold::STATUS_HELD, $hold->status);
        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $hold->hold_expires_at->toDateString()
        );
        $this->assertSame('held', $hold->events()->first()->event_type);
    }

    public function test_a_us_seller_gets_the_two_year_deadline(): void
    {
        $this->account->update(['country' => 'US', 'holding_period_days' => 730]);

        $hold = $this->engine->hold($this->account->fresh(), 'REF-US', 50.0);

        $this->assertSame(now()->addDays(730)->toDateString(), $hold->hold_expires_at->toDateString());
    }

    // ─── Buyer confirmation ───────────────────────────────────────────

    public function test_buyer_confirmation_releases_in_full(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $hold = $this->hold(100.0);

        $released = $this->engine->buyerConfirmed($hold);

        $this->assertSame(SettlementHold::STATUS_RELEASED, $released->status);
        $this->assertEquals(100.0, (float) $released->amount_released);
        $this->assertCount(1, $this->gateway->payouts);
        $this->assertEquals(100.0, $this->gateway->payouts[0]['amount']);
    }

    public function test_nothing_releases_when_the_merchant_has_no_matching_rule(): void
    {
        $hold = $this->hold(100.0);

        $result = $this->engine->buyerConfirmed($hold);

        $this->assertSame(SettlementHold::STATUS_HELD, $result->status);
        $this->assertCount(0, $this->gateway->payouts);
        $this->assertSame('release_blocked', $result->events->last()->event_type);
    }

    // ─── Elapsed days ─────────────────────────────────────────────────

    public function test_the_timer_does_not_release_early(): void
    {
        $this->rule(SettlementRule::CONDITION_ELAPSED_DAYS, ['config' => ['days' => 7]]);
        $hold = $this->hold();

        $this->engine->evaluate($hold, SettlementRule::CONDITION_ELAPSED_DAYS);

        $this->assertSame(SettlementHold::STATUS_HELD, $hold->fresh()->status);
        $this->assertCount(0, $this->gateway->payouts);
    }

    public function test_the_timer_releases_once_the_days_have_passed(): void
    {
        $this->rule(SettlementRule::CONDITION_ELAPSED_DAYS, ['config' => ['days' => 7]]);
        $hold = $this->hold();

        Carbon::setTestNow(now()->addDays(8));
        $result = $this->engine->evaluate($hold->fresh(), SettlementRule::CONDITION_ELAPSED_DAYS);
        Carbon::setTestNow();

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertCount(1, $this->gateway->payouts);
    }

    // ─── Disputes freeze everything ───────────────────────────────────

    public function test_a_dispute_freezes_the_hold(): void
    {
        $hold = $this->engine->raiseDispute($this->hold(), 'item not as described');

        $this->assertSame(SettlementHold::STATUS_DISPUTED, $hold->status);
        $this->assertTrue($hold->isFrozen());
    }

    public function test_a_disputed_hold_ignores_buyer_confirmation(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $hold = $this->engine->raiseDispute($this->hold(), 'damaged');

        $result = $this->engine->buyerConfirmed($hold);

        $this->assertSame(SettlementHold::STATUS_DISPUTED, $result->status);
        $this->assertCount(0, $this->gateway->payouts);
    }

    /**
     * The timer must not keep running through a dispute, or the money being
     * argued over pays itself out.
     */
    public function test_a_disputed_hold_ignores_an_elapsed_timer(): void
    {
        $this->rule(SettlementRule::CONDITION_ELAPSED_DAYS, ['config' => ['days' => 3]]);
        $hold = $this->engine->raiseDispute($this->hold(), 'not delivered');

        Carbon::setTestNow(now()->addDays(10));
        $this->engine->processDueHolds();
        Carbon::setTestNow();

        $this->assertSame(SettlementHold::STATUS_DISPUTED, $hold->fresh()->status);
        $this->assertCount(0, $this->gateway->payouts);
    }

    public function test_releasing_a_disputed_hold_directly_is_refused(): void
    {
        $hold = $this->engine->raiseDispute($this->hold(), 'dispute');

        $this->expectException(\RuntimeException::class);
        $this->engine->release($hold, 100.0);
    }

    public function test_resolving_in_the_seller_favour_releases(): void
    {
        $hold = $this->engine->raiseDispute($this->hold(100.0), 'dispute');

        $result = $this->engine->resolveDispute($hold, 'release', 'admin:1');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertCount(1, $this->gateway->payouts);
    }

    public function test_resolving_in_the_buyer_favour_refunds(): void
    {
        $hold = $this->engine->raiseDispute($this->hold(100.0), 'dispute');

        $result = $this->engine->resolveDispute($hold, 'refund', 'admin:1');

        $this->assertSame(SettlementHold::STATUS_REFUNDED, $result->status);
        $this->assertCount(0, $this->gateway->payouts);
        $this->assertCount(1, $this->gateway->refunds);
    }

    // ─── Partial releases ─────────────────────────────────────────────

    public function test_a_partial_rule_releases_only_its_share(): void
    {
        $this->rule(SettlementRule::CONDITION_MANUAL_APPROVAL, ['release_percentage' => 40]);
        $hold = $this->hold(100.0);

        $result = $this->engine->approvedManually($hold, 'admin:7');

        $this->assertSame(SettlementHold::STATUS_PART_RELEASED, $result->status);
        $this->assertEquals(40.0, (float) $result->amount_released);
        $this->assertEquals(60.0, $result->remaining());
    }

    /**
     * Two 60% releases must not pay out 120% of the money held.
     */
    public function test_repeated_partial_releases_cannot_exceed_the_held_amount(): void
    {
        $this->rule(SettlementRule::CONDITION_MANUAL_APPROVAL, ['release_percentage' => 60]);
        $hold = $this->hold(100.0);

        $this->engine->approvedManually($hold, 'admin:1');
        $result = $this->engine->approvedManually($hold->fresh(), 'admin:1');

        $this->assertEquals(100.0, (float) $result->amount_released);
        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertEquals(100.0, array_sum(array_column($this->gateway->payouts, 'amount')));
    }

    // ─── Stripe's deadline overrides the rules ────────────────────────

    public function test_a_hold_nearing_the_stripe_limit_is_force_released(): void
    {
        $hold = $this->hold(100.0);
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $summary = $this->engine->processDueHolds();

        $this->assertSame(1, $summary['forced']);
        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertStringContainsString('holding limit', $hold->fresh()->release_reason);
    }

    public function test_a_disputed_hold_at_the_limit_is_flagged_not_released(): void
    {
        $hold = $this->engine->raiseDispute($this->hold(100.0), 'dispute');
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $summary = $this->engine->processDueHolds();

        $this->assertSame(0, $summary['forced']);
        $this->assertCount(0, $this->gateway->payouts);
        $this->assertSame('expiry_warning', $hold->fresh()->events->last()->event_type);
    }

    // ─── Account state guards ─────────────────────────────────────────

    public function test_release_is_refused_when_the_seller_account_drifted(): void
    {
        $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION);
        $hold = $this->hold(100.0);

        // Someone switched the account back to automatic payouts.
        $this->account->update(['payout_schedule' => 'daily']);

        $this->expectException(\RuntimeException::class);

        try {
            $this->engine->buyerConfirmed($hold->fresh());
        } finally {
            $this->assertCount(0, $this->gateway->payouts);
            $this->assertSame('release_failed', $hold->fresh()->events->last()->event_type);
        }
    }

    // ─── Audit trail ──────────────────────────────────────────────────

    public function test_every_release_records_what_triggered_it(): void
    {
        $rule = $this->rule(SettlementRule::CONDITION_BUYER_CONFIRMATION, ['name' => 'Buyer confirmed receipt']);
        $hold = $this->hold(100.0);

        $result = $this->engine->buyerConfirmed($hold);

        $this->assertSame($rule->id, $result->released_by_rule_id);
        $this->assertSame('Buyer confirmed receipt', $result->release_reason);

        $types = $result->events->pluck('event_type')->all();
        $this->assertSame(['held', 'buyer_confirmed', 'released'], $types);

        $released = $result->events->firstWhere('event_type', 'released');
        $this->assertSame('pay_test_1', $released->detail['payout_id']);
    }
}

/**
 * Stands in for Stripe so the rules logic can be tested without the network.
 */
class FakeConnectGateway extends StripeConnectGateway
{
    public array $payouts = [];
    public array $refunds = [];

    public function __construct()
    {
        parent::__construct(['secret_key' => 'sk_test_fake']);
    }

    public function createPayout(string $accountId, float $amount, string $currency, array $metadata = []): array
    {
        $this->payouts[] = compact('accountId', 'amount', 'currency', 'metadata');

        return [
            'payout_id' => 'pay_test_' . count($this->payouts),
            'status' => 'pending',
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'arrival_date' => time(),
        ];
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = '', ?string $accountId = null): array
    {
        $this->refunds[] = compact('transactionReference', 'amount', 'reason', 'accountId');

        return ['status' => 'succeeded', 'reference' => 're_test_' . count($this->refunds), 'message' => 'Refund succeeded'];
    }
}
