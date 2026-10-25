<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use App\Services\SettlementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private ConnectedAccount $account;
    private ReconcileFakeGateway $gateway;
    private SettlementReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        $this->account = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_recon',
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

        $this->gateway = new ReconcileFakeGateway();

        $payments = new EscrowPaymentService();
        $payments->registerGateway('stripe_connect', $this->gateway);

        $this->service = new SettlementReconciliationService($payments);
    }

    private function hold(string $ref, float $amount, float $released, array $extra = []): SettlementHold
    {
        return SettlementHold::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'connected_account_id' => $this->account->id,
            'reference' => $ref,
            'amount' => $amount,
            'amount_released' => $released,
            'currency' => 'GBP',
            'status' => $released >= $amount ? SettlementHold::STATUS_RELEASED : SettlementHold::STATUS_HELD,
            'held_at' => now()->subDays(5),
            'hold_expires_at' => now()->addDays(85),
        ], $extra));
    }

    private function types(array $report): array
    {
        return array_column($report['discrepancies'], 'type');
    }

    public function test_matching_records_reconcile_cleanly(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertSame([], $report['discrepancies']);
        $this->assertSame(1, $report['checked']);
        $this->assertStringContainsString('no discrepancies', $this->service->summarise($report));
    }

    /**
     * The dangerous direction: Stripe moved more than we authorised.
     */
    public function test_an_overpayment_is_reported(): void
    {
        $this->hold('REF-1', 100.0, 50.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 50.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
            ['id' => 'po_2', 'amount' => 50.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::OVER_PAID, $this->types($report));
        $this->assertEquals(50.0, $report['discrepancies'][0]['delta']);
    }

    public function test_an_underpayment_is_reported(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 40.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::UNDER_PAID, $this->types($report));
        $this->assertEquals(-60.0, $report['discrepancies'][0]['delta']);
    }

    /**
     * Someone paying out by hand in the Stripe dashboard looks exactly like
     * this, and is the main reason to run reconciliation at all.
     */
    public function test_a_payout_no_hold_claims_is_reported(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
            ['id' => 'po_rogue', 'amount' => 25.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => []],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::ORPHAN_PAYOUT, $this->types($report));
    }

    /**
     * A failed payout must not read as money we released and Stripe lost.
     */
    public function test_failed_and_cancelled_payouts_are_ignored(): void
    {
        $this->hold('REF-1', 100.0, 0.0);
        $this->gateway->payouts = [
            ['id' => 'po_f', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'failed', 'metadata' => ['reference' => 'REF-1']],
            ['id' => 'po_c', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'canceled', 'metadata' => ['reference' => 'REF-1']],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertSame([], $report['discrepancies']);
    }

    public function test_in_transit_payouts_count_as_paid(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_t', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'in_transit', 'metadata' => ['reference' => 'REF-1']],
        ];

        $this->assertSame([], $this->service->reconcileMerchant($this->merchant->id)['discrepancies']);
    }

    public function test_a_hold_past_the_stripe_limit_is_reported(): void
    {
        $this->hold('REF-1', 100.0, 0.0, ['hold_expires_at' => now()->subDay()]);

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::STALE_HOLD, $this->types($report));
    }

    /**
     * Numbers agreeing is not enough if the account can no longer be held.
     */
    public function test_an_account_that_drifted_is_reported_even_when_balanced(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $this->account->update(['payout_schedule' => 'daily']);

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::ACCOUNT_DRIFTED, $this->types($report));
    }

    public function test_rounding_differences_are_tolerated(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 100.004, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $this->assertSame([], $this->service->reconcileMerchant($this->merchant->id)['discrepancies']);
    }

    /**
     * Stripe being unreachable is not the same as everything balancing.
     */
    public function test_an_unreachable_stripe_is_reported_not_silently_passed(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->gateway->throw = true;

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertContains(SettlementReconciliationService::UNREACHABLE, $this->types($report));
    }

    public function test_totals_cover_held_released_and_paid_out(): void
    {
        $this->hold('REF-1', 100.0, 100.0);
        $this->hold('REF-2', 80.0, 0.0);
        $this->gateway->payouts = [
            ['id' => 'po_1', 'amount' => 100.0, 'currency' => 'GBP', 'status' => 'paid', 'metadata' => ['reference' => 'REF-1']],
        ];

        $report = $this->service->reconcileMerchant($this->merchant->id);

        $this->assertEquals(80.0, $report['totals']['held']);
        $this->assertEquals(100.0, $report['totals']['released']);
        $this->assertEquals(100.0, $report['totals']['paid_out']);
    }
}

class ReconcileFakeGateway extends StripeConnectGateway
{
    public array $payouts = [];
    public bool $throw = false;

    public function __construct()
    {
        parent::__construct(['secret_key' => 'sk_test_fake']);
    }

    public function listPayouts(string $accountId, int $pageSize = 100): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Stripe is unreachable');
        }

        return $this->payouts;
    }
}
