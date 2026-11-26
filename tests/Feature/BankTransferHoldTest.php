<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Models\SettlementTransfer;
use App\PaymentGateways\ModulrGateway;
use App\Services\EscrowPaymentService;
use App\Services\SettlementRulesEngine;
use App\Support\BankDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * International holds paid by bank transfer through Modulr. Each hold gets
 * its own account for the payer to pay into; the money waits there and each
 * participant is paid out to their bank account when a rule releases them.
 */
class BankTransferHoldTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_ID = 'W1000001';
    private const WEBHOOK_SECRET = 'ssssssssssssssssssssssssssssssss';

    private const CHAIN = [
        ['to' => 'freight', 'percentage' => 10],
        ['to' => 'supplier', 'remainder' => true],
    ];

    private Merchant $merchant;
    private string $apiKey;
    private FakeModulr $modulr;

    /** @var array<string, ConnectedAccount> */
    private array $payees = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gateways.modulr.product_codes' => ['GBP' => 'O1200001', 'EUR' => 'EUR-000002'],
            'gateways.modulr.max_hold_days' => 90,
        ]);

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'BANK_TEST_' . bin2hex(random_bytes(12));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
        ]);

        $this->payees['freight'] = $this->payee('bnk_freight', ['name' => 'Rotterdam Freight', 'sort_code' => '040004', 'account_number' => '12345678']);
        $this->payees['supplier'] = $this->payee('bnk_supplier', ['name' => 'Supplier GmbH', 'iban' => 'DE89370400440532013000']);

        $this->modulr = new FakeModulr([
            'webhook_id' => self::WEBHOOK_ID,
            'webhook_secret' => self::WEBHOOK_SECRET,
            'product_codes' => ['GBP' => 'O1200001', 'EUR' => 'EUR-000002'],
        ]);

        $this->app->singleton(EscrowPaymentService::class, function () {
            $s = new EscrowPaymentService();
            $s->registerGateway('modulr', $this->modulr);

            return $s;
        });
    }

    private function payee(string $accountId, array $bank): ConnectedAccount
    {
        return ConnectedAccount::create(array_merge(BankDetails::normalise($bank), [
            'merchant_id' => $this->merchant->id,
            'provider' => ConnectedAccount::PROVIDER_BANK,
            'account_type' => ConnectedAccount::TYPE_RECIPIENT,
            'account_id' => $accountId,
            'seller_email' => $accountId . '@example.com',
            'country' => 'GB',
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]));
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    private function engine(): SettlementRulesEngine
    {
        return app(SettlementRulesEngine::class);
    }

    private function parties(): array
    {
        return array_map(fn ($a) => $a->account_id, $this->payees);
    }

    private function open(float $amount = 1000.0, string $ref = 'PO-1', string $currency = 'GBP'): SettlementHold
    {
        return $this->engine()->openBankTransfer($this->merchant->id, $ref, $amount, $currency, self::CHAIN, $this->parties(), null, 'refund');
    }

    private function payin(SettlementHold $hold, float $amount, string $paymentId = 'P0000001', string $currency = 'GBP'): array
    {
        return [
            'EventName' => 'PAYIN',
            'PaymentId' => $paymentId,
            'AccountId' => $hold->bank_account_id,
            'Amount' => number_format($amount, 2, '.', ''),
            'Currency' => $currency,
            'PayerName' => 'Importer Ltd',
            'Payer' => ['Name' => 'Importer Ltd', 'Identifier' => ['Type' => 'SCAN', 'SortCode' => '200000', 'AccountNumber' => '55779911']],
            'PaymentReference' => $hold->reference,
            'Type' => 'PI_FAST',
        ];
    }

    private function signed(): array
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $nonce = bin2hex(random_bytes(8));

        return [
            'Authorization' => ModulrGateway::sign(self::WEBHOOK_ID, self::WEBHOOK_SECRET, $date, $nonce),
            'Date' => $date,
            'x-mod-nonce' => $nonce,
        ];
    }

    /** Payment arrives and Modulr's balance agrees. */
    private function fund(SettlementHold $hold, ?float $amount = null): SettlementHold
    {
        $amount ??= (float) $hold->amount;
        $this->modulr->balances[$hold->bank_account_id] = $amount;

        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, $amount))->assertOk();

        return $hold->fresh();
    }

    private function rule(array $overrides = []): SettlementRule
    {
        return SettlementRule::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'name' => 'Delivered: release the shipment',
            'condition_type' => SettlementRule::CONDITION_MILESTONE,
            'config' => ['milestone' => 'delivered'],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ], $overrides));
    }

    // ─── Participants ─────────────────────────────────────────────────

    public function test_a_participant_is_added_with_bank_details_and_no_stripe_account(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/connected-accounts', [
            'email' => 'ap@carrier.example',
            'country' => 'GB',
            'type' => 'recipient',
            'label' => 'Carrier',
            'bank_account' => ['name' => 'Carrier Ltd', 'sort_code' => '04-00-04', 'account_number' => '87654321'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.provider', 'bank');
        $response->assertJsonPath('data.account.can_hold_shares', true);
        $response->assertJsonPath('data.account.bank_account.account_number', '••••4321');
        $response->assertJsonPath('data.onboarding_url', null);
        $this->assertStringStartsWith('bnk_', $response->json('data.account.account_id'));
    }

    public function test_an_invalid_iban_is_refused_when_the_participant_is_added(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/connected-accounts', [
            'email' => 'ap@maker.example',
            'country' => 'DE',
            'type' => 'recipient',
            'bank_account' => ['name' => 'Maker GmbH', 'iban' => 'DE89370400440532013001'],
        ])->assertStatus(422)->assertJsonPath('message', 'bank_account.iban is not a valid IBAN.');
    }

    public function test_iban_checksums_are_verified(): void
    {
        $this->assertTrue(BankDetails::validIban('GB82WEST12345698765432'));
        $this->assertTrue(BankDetails::validIban('NL91ABNA0417164300'));
        $this->assertFalse(BankDetails::validIban('GB82WEST12345698765433'));
        $this->assertFalse(BankDetails::validIban('not-an-iban'));
    }

    // ─── Opening ──────────────────────────────────────────────────────

    public function test_a_hold_is_opened_over_the_api_with_its_own_bank_details(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/holds', [
            'funding' => 'bank_transfer',
            'reference' => 'PO-2026-0412',
            'amount' => 1000,
            'currency' => 'GBP',
            'split' => self::CHAIN,
            'parties' => $this->parties(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'awaiting_payment');
        $response->assertJsonPath('data.funding', 'bank_transfer');
        $response->assertJsonPath('data.pay_to.sort_code', '000000');
        $response->assertJsonPath('data.pay_to.reference', 'PO-2026-0412');
        $response->assertJsonMissingPath('data.payment_url');
        $response->assertJsonPath('data.shares.0.amount', 100);
        $response->assertJsonPath('data.shares.1.amount', 900);

        $this->assertSame([['currency' => 'GBP', 'reference' => 'PO-2026-0412']], $this->modulr->accountsCreated);
    }

    public function test_opening_the_same_reference_twice_opens_one_account(): void
    {
        $body = ['funding' => 'bank_transfer', 'reference' => 'PO-9', 'amount' => 50, 'currency' => 'GBP', 'split' => self::CHAIN, 'parties' => $this->parties()];

        $this->withHeaders($this->auth())->postJson('/api/holds', $body)->assertCreated();
        $this->withHeaders($this->auth())->postJson('/api/holds', $body)->assertOk();

        $this->assertCount(1, $this->modulr->accountsCreated);
    }

    public function test_a_currency_without_a_product_code_is_refused(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/holds', [
            'funding' => 'bank_transfer', 'reference' => 'PO-USD', 'amount' => 50, 'currency' => 'USD',
            'split' => self::CHAIN, 'parties' => $this->parties(),
        ])->assertStatus(422)->assertJsonPath('message', 'Bank-transfer holds can be opened in GBP, EUR.');

        $this->assertSame([], $this->modulr->accountsCreated);
    }

    public function test_a_stripe_participant_cannot_take_a_share_of_a_bank_transfer_hold(): void
    {
        ConnectedAccount::create([
            'merchant_id' => $this->merchant->id, 'provider' => 'stripe', 'account_type' => 'recipient',
            'account_id' => 'acct_stripe', 'seller_email' => 's@example.com', 'country' => 'GB',
            'payouts_enabled' => true, 'details_submitted' => true, 'payout_schedule' => 'manual', 'dashboard_type' => 'none',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a Stripe account');

        try {
            $this->engine()->openBankTransfer($this->merchant->id, 'PO-X', 100, 'GBP',
                [['to' => 'acct_stripe', 'percentage' => 100]], [], null, null);
        } finally {
            $this->assertSame([], $this->modulr->accountsCreated, 'No account is opened for a hold that is refused');
        }
    }

    public function test_a_bank_participant_cannot_take_a_share_of_a_card_payment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is paid by bank transfer');

        $this->engine()->planShares($this->merchant->id, 100, 'GBP', self::CHAIN, $this->parties());
    }

    // ─── Paying in ────────────────────────────────────────────────────

    public function test_a_confirmed_payin_holds_the_payment(): void
    {
        $hold = $this->fund($this->open());

        $this->assertSame(SettlementHold::STATUS_HELD, $hold->status);
        $this->assertNotNull($hold->held_at);
        $this->assertTrue($hold->hold_expires_at->isSameDay(now()->addDays(90)));
        $this->assertSame(0, SettlementTransfer::count(), 'Nothing moves on payment: the money waits in the hold\'s account');
    }

    public function test_an_unsigned_notification_is_rejected(): void
    {
        $hold = $this->open();
        $this->modulr->balances[$hold->bank_account_id] = 1000;

        $this->postJson('/api/modulr/webhook', $this->payin($hold, 1000))->assertStatus(401);

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->fresh()->status);
    }

    public function test_a_payin_the_balance_does_not_confirm_is_not_trusted_and_is_retried(): void
    {
        $hold = $this->open();
        $this->modulr->balances[$hold->bank_account_id] = 0;

        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 1000))->assertStatus(500);

        $hold->refresh();
        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->status);
        $this->assertSame([], $hold->payins, 'An unconfirmed pay-in is not recorded, so Modulr\'s retry is processed');

        // Modulr resends once the balance has caught up.
        $this->modulr->balances[$hold->bank_account_id] = 1000;
        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 1000))->assertOk();

        $this->assertSame(SettlementHold::STATUS_HELD, $hold->fresh()->status);
    }

    public function test_a_short_payment_waits_for_the_rest(): void
    {
        $hold = $this->open();
        $this->modulr->balances[$hold->bank_account_id] = 600;

        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 600, 'P1'))->assertOk();
        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->fresh()->status);
        $this->assertSame(600.0, $hold->fresh()->amountReceived());

        $this->modulr->balances[$hold->bank_account_id] = 1000;
        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 400, 'P2'))->assertOk();

        $this->assertSame(SettlementHold::STATUS_HELD, $hold->fresh()->status);
    }

    public function test_a_resent_notification_is_only_counted_once(): void
    {
        $hold = $this->open();
        $this->modulr->balances[$hold->bank_account_id] = 1000;

        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 500, 'P1'))->assertOk();
        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 500, 'P1'))->assertOk();

        $hold->refresh();
        $this->assertSame(500.0, $hold->amountReceived());
        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->status);
    }

    public function test_money_in_the_wrong_currency_does_not_count(): void
    {
        $hold = $this->open();
        $this->modulr->balances[$hold->bank_account_id] = 1000;

        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', $this->payin($hold, 1000, 'P1', 'EUR'))->assertOk();

        $hold->refresh();
        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->status);
        $this->assertTrue($hold->events()->where('event_type', 'payment_rejected')->exists());
    }

    public function test_a_payin_for_an_unknown_account_is_acknowledged_and_ignored(): void
    {
        $this->withHeaders($this->signed())->postJson('/api/modulr/webhook', [
            'EventName' => 'PAYIN', 'PaymentId' => 'P1', 'AccountId' => 'A-NOBODY', 'Amount' => '10', 'Currency' => 'GBP',
        ])->assertOk()->assertJsonPath('message', 'Ignored: no hold for this account.');
    }

    // ─── Releasing ────────────────────────────────────────────────────

    public function test_a_milestone_pays_each_participant_to_their_bank_from_the_holds_account(): void
    {
        $this->rule();
        $hold = $this->fund($this->open());

        $hold = $this->engine()->milestoneReached($hold, 'delivered');

        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->status);
        $this->assertCount(2, $this->modulr->payouts);

        [$freight, $supplier] = $this->modulr->payouts;

        $this->assertSame($hold->bank_account_id, $freight['from']);
        $this->assertSame(['name' => 'Rotterdam Freight', 'sort_code' => '040004', 'account_number' => '12345678'], $freight['payee']);
        $this->assertSame(100.0, $freight['amount']);

        $this->assertSame(['name' => 'Supplier GmbH', 'iban' => 'DE89370400440532013000'], $supplier['payee']);
        $this->assertSame(900.0, $supplier['amount']);

        foreach ($this->modulr->payouts as $payout) {
            $this->assertLessThanOrEqual(50, strlen($payout['external_reference']));
        }

        $this->assertSame(2, SettlementTransfer::where('kind', 'payout')->where('status', 'paid')->count());
    }

    public function test_a_payout_that_fails_is_retried_with_the_same_reference(): void
    {
        $this->rule();
        $hold = $this->fund($this->open());

        $this->modulr->failPayouts = true;
        $hold = $this->engine()->milestoneReached($hold, 'delivered');

        $this->assertSame(2, SettlementTransfer::where('status', 'failed')->count());
        $firstRefs = array_column($this->modulr->payoutAttempts, 'external_reference');

        $this->modulr->failPayouts = false;
        $this->engine()->retryFailedMovements($hold);

        $this->assertSame(2, SettlementTransfer::where('status', 'paid')->count());
        $this->assertSame($firstRefs, array_column($this->modulr->payouts, 'external_reference'));
    }

    public function test_nothing_is_released_before_the_payment_arrives(): void
    {
        $this->rule();
        $hold = $this->open();

        try {
            $this->engine()->milestoneReached($hold, 'delivered');
            $this->fail('A milestone on an unpaid hold should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has not been received yet', $e->getMessage());
        }

        $this->assertSame(SettlementHold::STATUS_AWAITING_PAYMENT, $hold->fresh()->status);
        $this->assertSame([], $this->modulr->payouts);
    }

    // ─── Refunds and expiry ───────────────────────────────────────────

    public function test_a_refund_pays_the_payer_back_and_comes_from_every_share(): void
    {
        $hold = $this->fund($this->open());

        $hold = $this->engine()->refund($hold, 200, 'damaged');

        $this->assertSame(200.0, (float) $hold->amount_refunded);
        $this->assertSame(['name' => 'Importer Ltd', 'sort_code' => '200000', 'account_number' => '55779911'], $this->modulr->payouts[0]['payee']);
        $this->assertSame(200.0, $this->modulr->payouts[0]['amount']);

        $shares = $hold->shares()->get();
        $this->assertSame(20.0, (float) $shares[0]->amount_refunded);
        $this->assertSame(180.0, (float) $shares[1]->amount_refunded);
    }

    public function test_a_failed_refund_books_nothing(): void
    {
        $hold = $this->fund($this->open());
        $this->modulr->failPayouts = true;

        try {
            $this->engine()->refund($hold, 200, 'damaged');
            $this->fail('A failed refund should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nothing was refunded', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $hold->fresh()->amount_refunded);
        $this->assertSame(0.0, (float) $hold->shares()->sum('amount_refunded'));
    }

    public function test_at_the_limit_a_bank_transfer_hold_is_refunded_by_default(): void
    {
        $hold = $this->fund($this->open());
        $hold->update(['hold_expires_at' => now()->addDay()]);

        $this->engine()->processDueHolds();

        $hold->refresh();
        $this->assertSame(SettlementHold::STATUS_REFUNDED, $hold->status);
        $this->assertSame(1000.0, $this->modulr->payouts[0]['amount']);
    }
}

/** Stands in for Modulr: records what would have been sent. */
class FakeModulr extends ModulrGateway
{
    public array $accountsCreated = [];
    public array $balances = [];
    public array $payouts = [];
    public array $payoutAttempts = [];
    public bool $failPayouts = false;
    private int $next = 1;

    public function createAccount(string $currency, string $externalReference): array
    {
        $this->accountsCreated[] = ['currency' => strtoupper($currency), 'reference' => $externalReference];
        $id = 'A' . str_pad((string) $this->next++, 9, '0', STR_PAD_LEFT);

        return [
            'account_id' => $id,
            'status' => 'ACTIVE',
            'balance' => 0.0,
            'sort_code' => strtoupper($currency) === 'GBP' ? '000000' : null,
            'account_number' => strtoupper($currency) === 'GBP' ? '06524397' : null,
            'iban' => strtoupper($currency) === 'EUR' ? 'NL91MODR0123456789' : null,
            'bic' => strtoupper($currency) === 'EUR' ? 'MODRNL21' : null,
            'currency' => strtoupper($currency),
            'raw' => [],
        ];
    }

    public function getAccount(string $accountId): array
    {
        return ['account_id' => $accountId, 'status' => 'ACTIVE', 'balance' => $this->balances[$accountId] ?? 0.0,
            'sort_code' => null, 'account_number' => null, 'iban' => null, 'bic' => null, 'currency' => 'GBP', 'raw' => []];
    }

    public function payOut(string $sourceAccountId, array $payee, float $amount, string $currency, string $reference, string $externalReference): array
    {
        $attempt = ['from' => $sourceAccountId, 'payee' => $payee, 'amount' => $amount, 'currency' => $currency,
            'reference' => $reference, 'external_reference' => $externalReference];
        $this->payoutAttempts[] = $attempt;

        if ($this->failPayouts) {
            throw new \RuntimeException('Modulr timed out');
        }

        $this->payouts[] = $attempt;

        return ['payment_id' => 'P' . count($this->payouts), 'status' => 'pending', 'modulr_status' => 'SUBMITTED', 'raw' => []];
    }
}
