<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Models\SettlementShare;
use App\Models\SettlementTransfer;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use App\Services\SettlementRulesEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One payment divided between several participants the moment it is paid,
 * each share held in that participant's own account until a milestone
 * releases it. Nothing is ever held by Peppa.
 */
class SplitReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string $apiKey;
    private SplitFakeGateway $gateway;

    /** @var array<string, ConnectedAccount> */
    private array $accounts = [];

    private const CHAIN = [
        ['to' => 'freight', 'percentage' => 10],
        ['to' => 'customs', 'percentage' => 15],
        ['to' => 'supplier', 'remainder' => true],
    ];

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
            $this->accounts[$role] = $this->participant('acct_' . $role);
        }

        $this->gateway = new SplitFakeGateway();
        $this->app->singleton(EscrowPaymentService::class, function () {
            $s = new EscrowPaymentService();
            $s->registerGateway('stripe_connect', $this->gateway);

            return $s;
        });
    }

    private function participant(string $accountId, bool $ready = true, ?int $merchantId = null, string $country = 'GB'): ConnectedAccount
    {
        return ConnectedAccount::create([
            'merchant_id' => $merchantId ?? $this->merchant->id,
            'provider' => 'stripe',
            'account_type' => ConnectedAccount::TYPE_RECIPIENT,
            'account_id' => $accountId,
            'seller_email' => $accountId . '@example.com',
            'country' => $country,
            'charges_enabled' => false,
            'payouts_enabled' => $ready,
            'details_submitted' => $ready,
            'payout_schedule' => 'manual',
            'losses_payer' => 'application',
            'dashboard_type' => 'none',
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
            'name' => 'Delivered: release the shipment',
            'condition_type' => SettlementRule::CONDITION_MILESTONE,
            'config' => ['milestone' => 'delivered', 'confirmed_by' => 'freight'],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function parties(): array
    {
        return array_map(fn ($a) => $a->account_id, $this->accounts);
    }

    private function open(float $amount = 1000.0, string $ref = 'PO-1', array $extra = []): SettlementHold
    {
        return $this->engine()->openSplit(
            $this->merchant->id,
            $ref,
            $amount,
            $extra['currency'] ?? 'GBP',
            $extra['split'] ?? self::CHAIN,
            $extra['parties'] ?? $this->parties(),
            $extra['flow'] ?? null,
            $extra['on_expiry'] ?? 'refund',
            'payer@example.com',
            'https://partner.test/ok',
            'https://partner.test/cancel',
        );
    }

    /** A split hold whose payment has arrived and been shared out. */
    private function paidHold(float $amount = 1000.0, string $ref = 'PO-1', array $extra = []): SettlementHold
    {
        $hold = $this->open($amount, $ref, $extra);
        $this->gateway->payments['pi_' . $ref] = ['amount' => $amount, 'currency' => $extra['currency'] ?? 'GBP', 'status' => 'succeeded'];

        return $this->engine()->paymentReceived($hold, 'pi_' . $ref);
    }

    private function totals(array $calls): array
    {
        $totals = [];
        foreach ($calls as $c) {
            $totals[$c['account']] = round(($totals[$c['account']] ?? 0) + $c['amount'], 2);
        }

        return $totals;
    }

    // ─── Paying and sharing out ───────────────────────────────────────

    public function test_each_share_goes_straight_into_its_participants_account(): void
    {
        $hold = $this->open(100000.0);

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->status);
        $this->assertSame([], $this->gateway->transfers);

        $this->gateway->payments['pi_1'] = ['amount' => 100000.0, 'currency' => 'GBP', 'status' => 'succeeded'];
        $held = $this->engine()->paymentReceived($hold, 'pi_1');

        $this->assertSame(SettlementHold::STATUS_HELD, $held->status);
        $this->assertSame([
            'acct_freight' => 10000.0,
            'acct_customs' => 15000.0,
            'acct_supplier' => 75000.0,
        ], $this->totals($this->gateway->transfers));

        foreach ($this->gateway->transfers as $t) {
            $this->assertSame('ch_pi_1', $t['source']);
            $this->assertSame('hold_' . $hold->id, $t['transfer_group']);
        }

        // Shared out, but nothing paid out: every share is still held.
        $this->assertSame([], $this->gateway->payouts);
        $this->assertEquals(100000.0, $held->remaining());
    }

    public function test_the_deadline_is_the_earliest_participant_limit(): void
    {
        $this->participant('acct_us_agent', true, null, 'US');
        $hold = $this->paidHold(1000.0, 'PO-1', ['split' => [
            ['to' => 'acct_us_agent', 'percentage' => 50],
            ['to' => 'supplier', 'remainder' => true],
        ]]);

        $this->assertSame(now()->addDays(90)->toDateString(), $hold->hold_expires_at->toDateString());
    }

    public function test_an_underpayment_shares_nothing(): void
    {
        $hold = $this->open(500.0);

        $this->gateway->payments['pi_1'] = ['amount' => 450.0, 'currency' => 'GBP', 'status' => 'succeeded'];
        $result = $this->engine()->paymentReceived($hold, 'pi_1');

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $result->status);
        $this->assertSame([], $this->gateway->transfers);
    }

    public function test_a_participant_who_cannot_hold_a_share_is_refused_before_payment(): void
    {
        $this->accounts['customs']->update(['payout_schedule' => 'daily']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payouts are not set to manual');

        $this->open();
    }

    public function test_a_missing_participant_is_refused_before_payment(): void
    {
        $parties = $this->parties();
        unset($parties['customs']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No account is assigned to "customs"');

        $this->open(1000.0, 'PO-1', ['parties' => $parties]);
    }

    public function test_another_merchants_account_cannot_take_a_share(): void
    {
        $other = Merchant::factory()->create();
        $this->participant('acct_stranger', true, $other->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not one of your accounts');

        $this->open(1000.0, 'PO-1', ['split' => [['to' => 'acct_stranger', 'percentage' => 100]]]);
    }

    public function test_a_failed_share_transfer_is_retried_with_the_same_key(): void
    {
        $this->gateway->failNext['transfer:acct_customs'] = 1;
        $this->paidHold();

        $failed = SettlementTransfer::where('status', 'failed')->sole();
        $this->assertSame('customs', $failed->destination);

        $this->assertSame(['retried' => 1, 'failed' => 0], $this->engine()->retryFailedMovements());

        $keys = array_column(array_filter($this->gateway->attempts, fn ($a) => $a['account'] === 'acct_customs'), 'key');
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    // ─── Releasing ────────────────────────────────────────────────────

    public function test_a_confirmed_milestone_pays_each_participant_from_their_own_account(): void
    {
        $this->rule();
        $hold = $this->paidHold(100000.0);

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertSame([
            'acct_freight' => 10000.0,
            'acct_customs' => 15000.0,
            'acct_supplier' => 75000.0,
        ], $this->totals($this->gateway->payouts));
    }

    public function test_the_wrong_party_confirming_releases_nothing(): void
    {
        $this->rule();
        $hold = $this->paidHold();

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'supplier');

        $this->assertSame(SettlementHold::STATUS_HELD, $result->status);
        $this->assertSame([], $this->gateway->payouts);
        $this->assertStringContainsString('only counts when confirmed by freight', $result->events->last()->note);
    }

    public function test_confirming_the_same_milestone_twice_pays_once(): void
    {
        $this->rule(['release_percentage' => 50]);
        $hold = $this->paidHold(1000.0);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');
        $result = $this->engine()->milestoneReached($hold->fresh(), 'delivered', 'freight');

        $this->assertEquals(500.0, (float) $result->amount_released);
        $this->assertEquals(500.0, array_sum(array_column($this->gateway->payouts, 'amount')));
    }

    public function test_milestones_release_named_parties_in_stages(): void
    {
        $this->rule([
            'name' => 'Shipped: release the manufacturer',
            'config' => ['milestone' => 'shipped'],
            'parties' => ['manufacturer'],
        ]);
        $this->rule();

        $hold = $this->paidHold(1000.0, 'PO-1', ['split' => [
            ['to' => 'manufacturer', 'percentage' => 30],
            ['to' => 'freight', 'percentage' => 7],
            ['to' => 'supplier', 'remainder' => true],
        ]]);

        $afterShipping = $this->engine()->milestoneReached($hold, 'shipped', 'freight');
        $this->assertSame(SettlementHold::STATUS_PART_RELEASED, $afterShipping->status);
        $this->assertSame(['acct_manufacturer' => 300.0], $this->totals($this->gateway->payouts));

        $afterDelivery = $this->engine()->milestoneReached($afterShipping, 'delivered', 'freight');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $afterDelivery->status);
        $this->assertSame([
            'acct_manufacturer' => 300.0,
            'acct_freight' => 70.0,
            'acct_supplier' => 630.0,
        ], $this->totals($this->gateway->payouts));
    }

    public function test_a_percentage_rule_releases_that_share_of_every_participant(): void
    {
        $this->rule(['release_percentage' => 40]);
        $hold = $this->paidHold(1000.0);

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertEquals(400.0, (float) $result->amount_released);
        $this->assertSame([
            'acct_freight' => 40.0,
            'acct_customs' => 60.0,
            'acct_supplier' => 300.0,
        ], $this->totals($this->gateway->payouts));
    }

    public function test_the_platform_fee_is_never_transferred_or_paid_out(): void
    {
        $this->rule();
        $hold = $this->paidHold(1000.0, 'PO-1', ['split' => [
            ['to' => 'platform', 'percentage' => 2],
            ['to' => 'supplier', 'remainder' => true],
        ]]);

        $result = $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(['acct_supplier' => 980.0], $this->totals($this->gateway->transfers));
        $this->assertSame(['acct_supplier' => 980.0], $this->totals($this->gateway->payouts));
        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
    }

    public function test_rules_only_apply_to_their_own_flow(): void
    {
        $this->rule(['flow' => 'import', 'parties' => ['customs']]);
        $this->rule(['flow' => 'domestic', 'parties' => ['supplier']]);
        $hold = $this->paidHold(1000.0, 'PO-1', ['flow' => 'domestic']);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertSame(['acct_supplier' => 750.0], $this->totals($this->gateway->payouts));
    }

    public function test_a_payout_cannot_leave_before_the_share_arrives(): void
    {
        $this->rule();
        $this->gateway->failNext['transfer:acct_customs'] = 1;
        $hold = $this->paidHold(1000.0);

        $this->engine()->milestoneReached($hold, 'delivered', 'freight');

        $this->assertArrayNotHasKey('acct_customs', $this->totals($this->gateway->payouts));
        $this->assertSame('failed', SettlementTransfer::where('kind', 'payout')->where('destination', 'customs')->value('status'));

        // The sweep sends the transfer first, then the payout.
        $this->engine()->retryFailedMovements();

        $this->assertSame(150.0, $this->totals($this->gateway->payouts)['acct_customs']);
        $this->assertEquals(150.0, (float) SettlementShare::where('destination', 'customs')->value('amount_paid_out'));
    }

    public function test_a_participant_who_cannot_be_paid_out_stops_the_whole_release(): void
    {
        $this->rule();
        $hold = $this->paidHold(1000.0);
        $this->accounts['customs']->update(['payouts_enabled' => false]);

        try {
            $this->engine()->milestoneReached($hold, 'delivered', 'freight');
            $this->fail('Expected the release to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('customs', $e->getMessage());
        }

        $this->assertSame([], $this->gateway->payouts);
        $this->assertEquals(0.0, (float) $hold->fresh()->amount_released);
    }

    public function test_rules_naming_parties_do_not_touch_account_funded_holds(): void
    {
        $this->rule(['condition_type' => SettlementRule::CONDITION_MANUAL_APPROVAL, 'config' => [], 'parties' => ['supplier']]);

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
    }

    // ─── Disputes, refunds and the holding limit ──────────────────────

    public function test_a_disputed_split_hold_releases_everything_on_resolution(): void
    {
        $this->rule();
        $hold = $this->paidHold(1000.0);
        $this->engine()->raiseDispute($hold, 'Container short-shipped', 'payer');
        $this->engine()->milestoneReached($hold->fresh(), 'delivered', 'freight');

        $this->assertSame([], $this->gateway->payouts);

        $result = $this->engine()->resolveDispute($hold->fresh(), 'release', 'ops:jane');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $result->status);
        $this->assertEquals(1000.0, array_sum(array_column($this->gateway->payouts, 'amount')));
    }

    public function test_a_refund_is_pulled_back_from_every_share_in_proportion(): void
    {
        $hold = $this->paidHold(1000.0);

        $result = $this->engine()->refund($hold, 200.0, 'Two pallets damaged');

        $this->assertSame([
            'acct_freight' => 20.0,
            'acct_customs' => 30.0,
            'acct_supplier' => 150.0,
        ], $this->totals($this->gateway->reversals));
        $this->assertSame([['pi' => 'pi_PO-1', 'amount' => 200.0]], $this->gateway->refunds);
        $this->assertEquals(800.0, $result->remaining());
    }

    public function test_a_refund_only_returns_what_was_recovered(): void
    {
        $hold = $this->paidHold(1000.0);
        $this->gateway->failNext['reversal:acct_customs'] = 1;

        try {
            $this->engine()->refund($hold, 1000.0, 'Order cancelled');
            $this->fail('Expected an incomplete refund');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refunded 850.00 of 1000.00 GBP', $e->getMessage());
        }

        $this->assertSame([['pi' => 'pi_PO-1', 'amount' => 850.0]], $this->gateway->refunds);
        $this->assertEquals(150.0, $hold->fresh()->remaining());
    }

    public function test_at_the_limit_a_split_hold_is_refunded_by_default(): void
    {
        $hold = $this->paidHold(1000.0);
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $summary = $this->engine()->processDueHolds();

        $this->assertSame(1, $summary['forced']);
        $this->assertSame(SettlementHold::STATUS_REFUNDED, $hold->fresh()->status);
        $this->assertSame([], $this->gateway->payouts);
    }

    public function test_at_the_limit_a_split_hold_can_release_every_share(): void
    {
        $hold = $this->paidHold(1000.0, 'PO-1', ['on_expiry' => 'release']);
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $this->engine()->processDueHolds();

        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertEquals(1000.0, array_sum(array_column($this->gateway->payouts, 'amount')));
    }

    // ─── Over the API ─────────────────────────────────────────────────

    public function test_a_rule_naming_parties_is_created_over_the_api(): void
    {
        $this->postJson('/api/settlement-rules', [
            'name' => 'Shipped: release the manufacturer',
            'condition_type' => 'milestone',
            'milestone' => 'shipped',
            'confirmed_by' => 'freight',
            'parties' => ['manufacturer'],
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('data.milestone', 'shipped')
            ->assertJsonPath('data.parties.0', 'manufacturer');

        $this->postJson('/api/settlement-rules', [
            'name' => 'No milestone',
            'condition_type' => 'milestone',
        ], $this->auth())->assertStatus(422);
    }

    public function test_a_split_hold_is_opened_paid_and_released_over_the_api(): void
    {
        $this->rule();

        $this->postJson('/api/holds', [
            'funding' => 'split',
            'reference' => 'PO-77',
            'amount' => 2000,
            'currency' => 'EUR',
            'split' => self::CHAIN,
            'parties' => $this->parties(),
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.funding', 'split')
            ->assertJsonPath('data.payment_url', 'https://checkout.test/cs_PO-77')
            ->assertJsonPath('data.shares.2.to', 'supplier')
            ->assertJsonPath('data.shares.2.amount', 1500);

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
            ->assertJsonPath('data.shares.2.paid_out', 1500)
            ->assertJsonPath('data.shares.2.held', 0);
    }

    public function test_an_unready_participant_is_a_conflict_over_the_api(): void
    {
        $this->accounts['supplier']->update(['details_submitted' => false]);

        $this->postJson('/api/holds', [
            'funding' => 'split',
            'reference' => 'PO-1',
            'amount' => 100,
            'currency' => 'USD',
            'split' => self::CHAIN,
            'parties' => $this->parties(),
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())->assertStatus(409)->assertJsonPath('blockers.0', fn ($b) => str_contains($b, 'supplier'));
    }

    public function test_an_unsound_split_is_refused_over_the_api(): void
    {
        $this->postJson('/api/holds', [
            'funding' => 'split',
            'reference' => 'PO-1',
            'amount' => 100,
            'currency' => 'USD',
            'split' => [['to' => 'freight', 'percentage' => 60], ['to' => 'customs', 'percentage' => 60]],
            'parties' => $this->parties(),
            'success_url' => 'https://partner.test/ok',
            'cancel_url' => 'https://partner.test/cancel',
        ], $this->auth())->assertStatus(422);
    }

    public function test_a_participant_is_created_locked(): void
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
        $this->assertSame(['acct_new_1'], $this->gateway->manualPayouts);
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
    public array $payouts = [];
    public array $reversals = [];
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

    private function maybeFail(string $kind, string $account): void
    {
        $key = $kind . ':' . $account;

        if (($this->failNext[$key] ?? 0) > 0) {
            $this->failNext[$key]--;
            throw new \RuntimeException('Stripe timed out');
        }
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
        return ['charge_id' => 'ch_' . $paymentIntentId] + $this->payments[$paymentIntentId];
    }

    public function createTransfer(
        string $destinationAccountId, float $amount, string $currency, string $transferGroup,
        ?string $sourceCharge, string $idempotencyKey, array $metadata = [],
    ): array {
        $this->attempts[] = ['account' => $destinationAccountId, 'key' => $idempotencyKey];
        $this->maybeFail('transfer', $destinationAccountId);

        $this->transfers[] = [
            'account' => $destinationAccountId,
            'amount' => $amount,
            'transfer_group' => $transferGroup,
            'source' => $sourceCharge,
        ];

        return ['transfer_id' => 'tr_' . count($this->transfers), 'amount' => $amount, 'currency' => $currency];
    }

    public function createPayout(string $accountId, float $amount, string $currency, array $metadata = [], ?string $idempotencyKey = null): array
    {
        $this->maybeFail('payout', $accountId);
        $this->payouts[] = ['account' => $accountId, 'amount' => $amount, 'key' => $idempotencyKey];

        return ['payout_id' => 'po_' . count($this->payouts), 'status' => 'pending', 'amount' => $amount, 'currency' => $currency, 'arrival_date' => time()];
    }

    public function reverseTransfer(string $transferId, float $amount, string $currency, string $idempotencyKey): array
    {
        $account = null;
        foreach ($this->transfers as $i => $t) {
            if ('tr_' . ($i + 1) === $transferId) {
                $account = $t['account'];
            }
        }

        $this->maybeFail('reversal', (string) $account);
        $this->reversals[] = ['account' => $account, 'amount' => $amount];

        return ['reversal_id' => 'trr_' . count($this->reversals), 'amount' => $amount];
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
            'dashboard_type' => 'none',
            'platform_bears_losses' => true,
            'payout_schedule' => 'manual',
            'requirements_due' => [],
            'holding_period_days' => 90,
        ];
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return ['url' => 'https://connect.test/' . $accountId, 'expires_at' => time() + 300];
    }
}
