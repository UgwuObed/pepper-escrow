<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Models\SettlementTransfer;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use App\Services\SettlementRulesEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One payment, held on the platform, released in shares to several parties
 * when a milestone is confirmed.
 */
class SplitReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string $apiKey;
    private SplitFakeGateway $gateway;

    /** @var array<string, ConnectedAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'SPLIT_TEST_' . bin2hex(random_bytes(12));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
        ]);

        foreach (['manufacturer', 'freight', 'customs', 'supplier'] as $role) {
            $this->accounts[$role] = $this->recipient('acct_' . $role);
        }

        $this->gateway = new SplitFakeGateway();
        $this->app->singleton(EscrowPaymentService::class, function () {
            $s = new EscrowPaymentService();
            $s->registerGateway('stripe_connect', $this->gateway);

            return $s;
        });
    }

    private function recipient(string $accountId, bool $ready = true, ?int $merchantId = null): ConnectedAccount
    {
        return ConnectedAccount::create([
            'merchant_id' => $merchantId ?? $this->merchant->id,
            'provider' => 'stripe',
            'account_type' => ConnectedAccount::TYPE_RECIPIENT,
            'account_id' => $accountId,
            'seller_email' => $accountId . '@example.com',
            'country' => 'GB',
            'charges_enabled' => false,
            'payouts_enabled' => $ready,
            'details_submitted' => $ready,
            'losses_payer' => 'application',
            'dashboard_type' => 'express',
            'holding_period_days' => 90,
        ]);
    }

    private function engine(): SettlementRulesEngine
    {
        return app(SettlementRulesEngine::class);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    private function rule(array $overrides = []): SettlementRule
    {
        return SettlementRule::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'name' => 'Delivered: pay the chain',
            'condition_type' => SettlementRule::CONDITION_MILESTONE,
            'config' => ['milestone' => 'delivered', 'confirmed_by' => 'freight'],
            'release_percentage' => 100,
            'destinations' => [
                ['to' => 'freight', 'percentage' => 10],
                ['to' => 'customs', 'percentage' => 15],
                ['to' => 'supplier', 'remainder' => true],
            ],
            'priority' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function parties(): array
    {
        return array_map(fn ($a) => $a->account_id, $this->accounts);
    }

    /** A pooled hold whose payment has already arrived. */
    private function paidHold(float $amount = 1000.0, string $ref = 'PO-1', array $extra = []): SettlementHold
    {
        $hold = $this->engine()->openPooled(
            $this->merchant->id,
            $ref,
            $amount,
            'GBP',
            $extra['parties'] ?? $this->parties(),
            $extra['flow'] ?? null,
            $extra['on_expiry'] ?? 'refund',
            'payer@example.com',
            'https://partner.test/ok',
            'https://partner.test/cancel',
        );

        $this->gateway->payments['pi_' . $ref] = ['amount' => $amount, 'currency' => 'GBP', 'status' => 'succeeded'];

        return $this->engine()->paymentReceived($hold, 'pi_' . $ref);
    }

    private function transferTotals(): array
    {
        $totals = [];
        foreach ($this->gateway->transfers as $t) {
            $totals[$t['destination']] = ($totals[$t['destination']] ?? 0) + $t['amount'];
        }

        return $totals;
    }

    // ─── Collecting the payment ───────────────────────────────────────

    public function test_a_pooled_hold_waits_for_payment_then_holds(): void
    {
        $hold = $this->engine()->openPooled(
            $this->merchant->id, 'PO-1', 500.0, 'GBP', $this->parties(), null, 'refund',
            null, 'https://partner.test/ok', 'https://partner.test/cancel',
        );

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->status);
        $this->assertSame('https://checkout.test/cs_PO-1', $hold->payment_url);
        $this->assertSame('hold_' . $hold->id, $this->gateway->checkouts[0]['transfer_group']);

        $this->gateway->payments['pi_1'] = ['amount' => 500.0, 'currency' => 'GBP', 'status' => 'succeeded'];
        $held = $this->engine()->paymentReceived($hold, 'pi_1');

        $this->assertSame(SettlementHold::STATUS_HELD, $held->status);
        $this->assertSame('ch_pi_1', $held->charge_id);
        $this->assertSame(now()->addDays(90)->toDateString(), $held->hold_expires_at->toDateString());
    }

    public function test_an_underpayment_does_not_open_the_hold(): void
    {
        $hold = $this->engine()->openPooled(
            $this->merchant->id, 'PO-1', 500.0, 'GBP', $this->parties(), null, 'refund',
            null, 'https://partner.test/ok', 'https://partner.test/cancel',
        );

        $this->gateway->payments['pi_1'] = ['amount' => 450.0, 'currency' => 'GBP', 'status' => 'succeeded'];
        $result = $this->engine()->paymentReceived($hold, 'pi_1');

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $result->status);
        $this->assertSame('payment_rejected', $result->events->last()->event_type);
    }

    public function test_a_milestone_cannot_be_reported_before_payment(): void
    {
        $hold = $this->engine()->openPooled(
            $this->merchant->id, 'PO-1', 500.0, 'GBP', $this->parties(), null, 'refund',
            null, 'https://partner.test/ok', 'https://partner.test/cancel',
        );

        $this->expectException(\RuntimeException::class);
        $this->engine()->milestoneReached($hold, 'delivered', 'freight');
    }

    // ─── Splitting the release ────────────────────────────────────────

    public function test_a_confirmed_milestone_splits_the_payment_across_the_parties(): void
    {
        $this->rule();
        $hold = $this->paidHold(100000.0);

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertSame([
            'acct_freight' => 10000.0,
            'acct_customs' => 15000.0,
            'acct_supplier' => 75000.0,
        ], $this->transferTotals());

        foreach ($this->gateway->transfers as $t) {
            $this->assertSame('hold_' . $hold->id, $t['transfer_group']);
            $this->assertSame('ch_pi_PO-1', $t['source']);
        }

        $this->assertSame(3, SettlementTransfer::where('status', 'paid')->count());
    }

    public function test_the_wrong_party_confirming_releases_nothing(): void
    {
        $this->rule();
        $hold = $this->paidHold();

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'supplier');

        $this->assertSame(SettlementHold::STATUS_HELD, $result->status);
        $this->assertSame([], $this->gateway->transfers);
        $this->assertStringContainsString('only counts when confirmed by freight', $result->events->last()->note);
    }

    public function test_confirming_the_same_milestone_twice_pays_once(): void
    {
        $this->rule(['release_percentage' => 50]);
        $hold = $this->paidHold(1000.0);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');
        $result = $this->engine()->milestoneReached($hold->fresh(), 'delivered', 'freight');

        $this->assertEquals(500.0, (float) $result->amount_released);
        $this->assertEquals(500.0, array_sum(array_column($this->gateway->transfers, 'amount')));
    }

    public function test_milestones_release_in_stages(): void
    {
        $this->rule([
            'name' => 'Shipped: advance to the manufacturer',
            'config' => ['milestone' => 'shipped'],
            'release_percentage' => 30,
            'destinations' => [['to' => 'manufacturer', 'percentage' => 100]],
        ]);
        $this->rule();
        $hold = $this->paidHold(1000.0);

        $afterShipping = $this->engine()->milestoneReached($hold, 'shipped', 'freight');
        $this->assertSame(SettlementHold::STATUS_PART_RELEASED, $afterShipping->status);
        $this->assertEquals(700.0, $afterShipping->remaining());

        $afterDelivery = $this->engine()->milestoneReached($afterShipping, 'delivered', 'freight');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $afterDelivery->status);
        $this->assertSame([
            'acct_manufacturer' => 300.0,
            'acct_freight' => 70.0,
            'acct_customs' => 105.0,
            'acct_supplier' => 525.0,
        ], $this->transferTotals());
    }

    public function test_the_platform_share_stays_on_the_platform(): void
    {
        $this->rule(['destinations' => [
            ['to' => 'platform', 'percentage' => 2],
            ['to' => 'supplier', 'remainder' => true],
        ]]);
        $hold = $this->paidHold(1000.0);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(['acct_supplier' => 980.0], $this->transferTotals());
        $this->assertEquals(20.0, (float) SettlementTransfer::where('status', 'retained')->value('amount'));
    }

    public function test_a_destination_can_name_an_account_directly(): void
    {
        $this->recipient('acct_tax_authority');
        $this->rule(['destinations' => [
            ['to' => 'acct_tax_authority', 'amount' => 100],
            ['to' => 'supplier', 'remainder' => true],
        ]]);
        $hold = $this->paidHold(1000.0);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(['acct_tax_authority' => 100.0, 'acct_supplier' => 900.0], $this->transferTotals());
    }

    public function test_rules_only_apply_to_their_own_flow(): void
    {
        $this->rule(['flow' => 'import', 'destinations' => [['to' => 'customs', 'percentage' => 100]]]);
        $this->rule(['flow' => 'domestic', 'destinations' => [['to' => 'supplier', 'percentage' => 100]]]);
        $hold = $this->paidHold(1000.0, 'PO-1', ['flow' => 'domestic']);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(['acct_supplier' => 1000.0], $this->transferTotals());
    }

    public function test_a_missing_party_stops_the_whole_release(): void
    {
        $this->rule();
        $parties = $this->parties();
        unset($parties['customs']);
        $hold = $this->paidHold(1000.0, 'PO-1', ['parties' => $parties]);

        try {
            $this->engine()->milestoneReached($hold, 'delivered', 'freight');
            $this->fail('Expected the release to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No account is assigned to "customs"', $e->getMessage());
        }

        $fresh = $hold->fresh();
        $this->assertSame([], $this->gateway->transfers);
        $this->assertEquals(0.0, (float) $fresh->amount_released);
        $this->assertSame(0, SettlementTransfer::count());
    }

    public function test_a_party_still_onboarding_stops_the_whole_release(): void
    {
        $this->accounts['customs']->update(['payouts_enabled' => false]);
        $this->rule();
        $hold = $this->paidHold(1000.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot receive money yet');

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');
    }

    public function test_another_merchants_account_cannot_be_paid(): void
    {
        $other = Merchant::factory()->create();
        $this->recipient('acct_stranger', true, $other->id);
        $this->rule(['destinations' => [['to' => 'acct_stranger', 'percentage' => 100]]]);
        $hold = $this->paidHold();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not one of your accounts');

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');
    }

    public function test_a_failed_transfer_is_retried_with_the_same_key(): void
    {
        $this->rule();
        $this->gateway->failNext['acct_customs'] = 1;
        $hold = $this->paidHold(1000.0);

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        // The release is decided and recorded even though one share failed.
        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $failed = SettlementTransfer::where('status', 'failed')->sole();
        $this->assertSame('customs', $failed->destination);

        $summary = $this->engine()->retryFailedTransfers();

        $this->assertSame(['retried' => 1, 'failed' => 0], $summary);
        $this->assertSame('paid', $failed->fresh()->status);

        $keys = array_column(array_filter($this->gateway->attempts, fn ($a) => $a['destination'] === 'acct_customs'), 'key');
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_split_rules_do_not_touch_account_funded_holds(): void
    {
        $this->rule(['condition_type' => SettlementRule::CONDITION_MANUAL_APPROVAL, 'config' => []]);

        $holding = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_holding',
            'seller_email' => 'h@example.com',
            'country' => 'GB',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'payout_schedule' => 'manual',
            'losses_payer' => 'stripe',
            'dashboard_type' => 'none',
            'holding_period_days' => 90,
        ]);

        $hold = $this->engine()->hold($holding, 'ACC-1', 100.0, 'GBP', 'pi_acc');
        $result = $this->engine()->approvedManually($hold, 'ops:1');

        $this->assertSame(SettlementHold::STATUS_HELD, $result->status);
        $this->assertSame([], $this->gateway->transfers);
    }

    // ─── Disputes, refunds and the holding limit ──────────────────────

    public function test_a_disputed_pooled_hold_releases_only_with_destinations(): void
    {
        $this->rule();
        $hold = $this->paidHold(1000.0);
        $this->engine()->raiseDispute($hold, 'Container short-shipped', 'buyer');

        $this->assertSame([], $this->gateway->transfers);

        try {
            $this->engine()->resolveDispute($hold->fresh(), 'release', 'ops:jane');
            $this->fail('Expected destinations to be required');
        } catch (\RuntimeException $e) {
            $this->assertSame(SettlementHold::STATUS_DISPUTED, $hold->fresh()->status);
        }

        $result = $this->engine()->resolveDispute($hold->fresh(), 'release', 'ops:jane', [
            ['to' => 'supplier', 'percentage' => 80],
            ['to' => 'freight', 'remainder' => true],
        ]);

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertSame(['acct_supplier' => 800.0, 'acct_freight' => 200.0], $this->transferTotals());
    }

    public function test_a_pooled_refund_comes_from_the_platform_payment(): void
    {
        $hold = $this->paidHold(1000.0);

        $result = $this->engine()->refund($hold, 250.0, 'Two pallets damaged');

        $this->assertEquals(750.0, $result->remaining());
        $this->assertSame([['pi' => 'pi_PO-1', 'amount' => 250.0]], $this->gateway->refunds);
    }

    public function test_at_the_limit_a_pooled_hold_is_refunded_by_default(): void
    {
        $hold = $this->paidHold(1000.0);
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $summary = $this->engine()->processDueHolds();

        $this->assertSame(1, $summary['forced']);
        $this->assertSame(SettlementHold::STATUS_REFUNDED, $hold->fresh()->status);
        $this->assertSame([], $this->gateway->transfers);
    }

    public function test_at_the_limit_a_pooled_hold_can_go_to_a_named_party(): void
    {
        $hold = $this->paidHold(1000.0, 'PO-1', ['on_expiry' => 'supplier']);
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $this->engine()->processDueHolds();

        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertSame(['acct_supplier' => 1000.0], $this->transferTotals());
    }

    // ─── Over the API ─────────────────────────────────────────────────

    public function test_a_split_rule_is_created_over_the_api(): void
    {
        $response = $this->postJson('/api/settlement-rules', [
            'name' => 'Delivered: pay the chain',
            'condition_type' => 'milestone',
            'milestone' => 'delivered',
            'confirmed_by' => 'freight',
            'destinations' => [
                ['to' => 'freight', 'percentage' => 10],
                ['to' => 'customs', 'percentage' => 15],
                ['to' => 'supplier', 'remainder' => true],
            ],
        ], $this->auth());

        $response->assertStatus(201)
            ->assertJsonPath('data.milestone', 'delivered')
            ->assertJsonPath('data.confirmed_by', 'freight')
            ->assertJsonPath('data.destinations.2.to', 'supplier');
    }

    public function test_an_unsound_split_rule_is_refused(): void
    {
        $this->postJson('/api/settlement-rules', [
            'name' => 'Bad',
            'condition_type' => 'milestone',
            'milestone' => 'delivered',
            'destinations' => [
                ['to' => 'freight', 'percentage' => 60],
                ['to' => 'customs', 'percentage' => 60],
            ],
        ], $this->auth())->assertStatus(422)->assertJsonPath('status', 'error');

        $this->postJson('/api/settlement-rules', [
            'name' => 'No milestone',
            'condition_type' => 'milestone',
        ], $this->auth())->assertStatus(422);
    }

    public function test_a_pooled_hold_is_opened_and_released_over_the_api(): void
    {
        $this->rule();

        $this->postJson('/api/holds', [
            'funding' => 'pooled',
            'reference' => 'PO-77',
            'amount' => 2000,
            'currency' => 'EUR',
            'parties' => $this->parties(),
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.funding', 'pooled')
            ->assertJsonPath('data.payment_url', 'https://checkout.test/cs_PO-77');

        $hold = SettlementHold::where('reference', 'PO-77')->sole();
        $this->gateway->payments['pi_77'] = ['amount' => 2000.0, 'currency' => 'EUR', 'status' => 'succeeded'];

        $this->webhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $hold->checkout_session_id,
                'payment_intent' => 'pi_77',
                'metadata' => ['peppa_hold_id' => (string) $hold->id],
            ]],
        ])->assertStatus(200)->assertJsonPath('hold_status', 'held');

        $this->postJson('/api/holds/PO-77/milestones', [
            'milestone' => 'delivered',
            'confirmed_by' => 'freight',
            'actor' => 'freight:dhl',
        ], $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'released')
            ->assertJsonCount(3, 'data.transfers')
            ->assertJsonPath('data.transfers.2.to', 'supplier')
            ->assertJsonPath('data.transfers.2.amount', 1500);
    }

    public function test_parties_must_be_your_own_recipient_accounts(): void
    {
        $this->postJson('/api/holds', [
            'funding' => 'pooled',
            'reference' => 'PO-1',
            'amount' => 100,
            'currency' => 'USD',
            'parties' => ['supplier' => 'acct_nobody'],
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'supplier'));
    }

    public function test_on_expiry_must_name_a_party(): void
    {
        $this->postJson('/api/holds', [
            'funding' => 'pooled',
            'reference' => 'PO-1',
            'amount' => 100,
            'currency' => 'USD',
            'parties' => $this->parties(),
            'on_expiry' => 'warehouse',
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())->assertStatus(422);
    }

    public function test_a_recipient_account_is_created_without_arming_a_hold(): void
    {
        $this->postJson('/api/connected-accounts', [
            'email' => 'freight@example.com',
            'country' => 'GB',
            'type' => 'recipient',
            'label' => 'Freight forwarder',
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('data.account.type', 'recipient')
            ->assertJsonPath('data.account.label', 'Freight forwarder');

        $this->assertSame('recipient', $this->gateway->createdAccounts[0]['type']);
        $this->assertSame([], $this->gateway->manualPayouts);
    }

    private function webhook(array $event)
    {
        $payload = json_encode($event);
        $t = time();
        $signature = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_platform');

        return $this->call('POST', '/api/stripe/connect/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }
}

class SplitFakeGateway extends StripeConnectGateway
{
    public array $checkouts = [];
    public array $payments = [];
    public array $transfers = [];
    public array $attempts = [];
    public array $refunds = [];
    public array $failNext = [];
    public array $createdAccounts = [];
    public array $manualPayouts = [];

    public function __construct()
    {
        parent::__construct([
            'secret_key' => 'sk_test_fake',
            'webhook_secret' => 'whsec_platform',
            'platform_country' => 'GB',
        ]);
    }

    public function createPlatformCheckout(
        float $amount, string $currency, string $reference, string $transferGroup,
        ?string $customerEmail, string $successUrl, string $cancelUrl, array $metadata = [],
    ): array {
        $this->checkouts[] = ['transfer_group' => $transferGroup, 'amount' => $amount, 'metadata' => $metadata];

        return ['session_id' => 'cs_' . $reference, 'payment_url' => 'https://checkout.test/cs_' . $reference];
    }

    public function platformPayment(string $paymentIntentId): array
    {
        $p = $this->payments[$paymentIntentId];

        return ['charge_id' => 'ch_' . $paymentIntentId] + $p;
    }

    public function createTransfer(
        string $destinationAccountId, float $amount, string $currency, string $transferGroup,
        ?string $sourceCharge, string $idempotencyKey, array $metadata = [],
    ): array {
        $this->attempts[] = ['destination' => $destinationAccountId, 'key' => $idempotencyKey];

        if (($this->failNext[$destinationAccountId] ?? 0) > 0) {
            $this->failNext[$destinationAccountId]--;
            throw new \RuntimeException('Stripe timed out');
        }

        $this->transfers[] = [
            'destination' => $destinationAccountId,
            'amount' => $amount,
            'currency' => $currency,
            'transfer_group' => $transferGroup,
            'source' => $sourceCharge,
        ];

        return ['transfer_id' => 'tr_' . count($this->transfers), 'amount' => $amount, 'currency' => $currency];
    }

    public function refundPlatformPayment(string $paymentIntentId, float $amount, string $currency, string $reason = ''): array
    {
        $this->refunds[] = ['pi' => $paymentIntentId, 'amount' => $amount];

        return ['status' => 'succeeded', 'reference' => 're_' . count($this->refunds), 'message' => 'Refund succeeded'];
    }

    public function createConnectedAccount(string $email, string $country, array $metadata = [], string $type = 'holding'): array
    {
        $this->createdAccounts[] = compact('email', 'country', 'type');

        return [
            'account_id' => 'acct_new_' . count($this->createdAccounts),
            'country' => strtoupper($country),
            'holding_period_days' => 90,
            'charges_enabled' => false,
            'payouts_enabled' => false,
        ];
    }

    public function setManualPayouts(string $accountId): array
    {
        $this->manualPayouts[] = $accountId;

        return ['account_id' => $accountId, 'payout_schedule' => 'manual'];
    }

    public function getAccountStatus(string $accountId): array
    {
        return [
            'account_id' => $accountId,
            'country' => 'GB',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'losses_payer' => 'application',
            'dashboard_type' => 'express',
            'platform_bears_losses' => true,
            'payout_schedule' => 'daily',
            'requirements_due' => [],
            'holding_period_days' => 90,
        ];
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return ['url' => 'https://connect.test/' . $accountId, 'expires_at' => time() + 300];
    }
}
