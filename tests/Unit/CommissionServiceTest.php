<?php

namespace Tests\Unit;

use App\Models\CommissionRule;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CommissionService::class);
    }

    public function test_calculates_percentage_commission(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'rate_type' => 'percentage',
            'rate_value' => 2.5,
        ]);

        $result = $this->service->calculate($merchant, $type->id, 100000);

        $this->assertEquals(2500, $result['commission']);
        $this->assertEquals(97500, $result['net']);
        $this->assertNotNull($result['rule']);
    }

    public function test_calculates_flat_commission(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->flat()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
        ]);

        $result = $this->service->calculate($merchant, $type->id, 100000);

        $this->assertEquals(500, $result['commission']);
        $this->assertEquals(99500, $result['net']);
    }

    public function test_respects_cap(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->withCap(2000)->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'rate_type' => 'percentage',
            'rate_value' => 10,
        ]);

        $result = $this->service->calculate($merchant, $type->id, 100000);

        $this->assertEquals(2000, $result['commission']);
    }

    public function test_respects_min_max_amount(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'min_amount' => 50000,
            'max_amount' => 200000,
        ]);

        $below = $this->service->calculate($merchant, $type->id, 10000);
        $within = $this->service->calculate($merchant, $type->id, 100000);
        $above = $this->service->calculate($merchant, $type->id, 500000);

        $this->assertEquals(0, $below['commission'], 'Below min should not match');
        $this->assertGreaterThan(0, $within['commission'], 'Within range should match');
        $this->assertEquals(0, $above['commission'], 'Above max should not match');
    }

    public function test_returns_highest_priority_rule(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'priority' => 0,
            'rate_value' => 1,
        ]);
        CommissionRule::factory()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'priority' => 10,
            'rate_value' => 5,
        ]);

        $result = $this->service->calculate($merchant, $type->id, 100000);

        $this->assertEquals(5000, $result['commission']);
    }

    public function test_applies_commission_to_transaction(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);
        CommissionRule::factory()->create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $type->id,
            'rate_type' => 'percentage',
            'rate_value' => 3.0,
        ]);

        $transaction = Transaction::factory()->create([
            'merchantid' => $merchant->id,
            'appid' => (string) $merchant->id,
            'transaction_type_id' => $type->id,
            'amount' => 200000,
        ]);

        $updated = $this->service->applyToTransaction($transaction, $merchant);

        $this->assertEquals(6000, (float) $updated->commission_amount);
        $this->assertEquals(194000, (float) $updated->net_amount);
    }

    public function test_returns_zero_when_no_rule_matches(): void
    {
        $merchant = Merchant::factory()->create();
        $type = TransactionType::factory()->create(['merchant_id' => $merchant->id]);

        $result = $this->service->calculate($merchant, $type->id, 100000);

        $this->assertEquals(0, $result['commission']);
        $this->assertEquals(100000, $result['net']);
        $this->assertNull($result['rule']);
    }

    public function test_seeds_default_commission_rules(): void
    {
        $merchant = Merchant::factory()->create();
        $escrow = TransactionType::factory()->create([
            'merchant_id' => $merchant->id,
            'slug' => 'escrow',
        ]);
        $sale = TransactionType::factory()->create([
            'merchant_id' => $merchant->id,
            'slug' => 'direct_sale',
        ]);

        $this->service->seedDefaults($merchant);

        $this->assertCount(2, CommissionRule::where('merchant_id', $merchant->id)->get());
        foreach (CommissionRule::where('merchant_id', $merchant->id)->get() as $rule) {
            $this->assertEquals(2.5, (float) $rule->rate_value);
            $this->assertEquals('percentage', $rule->rate_type);
        }
    }
}
