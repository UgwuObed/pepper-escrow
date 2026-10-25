<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSettlementHoldsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private ConnectedAccount $account;
    private FakeConnectGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        $this->account = ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_cmd',
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

        // Swap Stripe out so the command exercises real logic without network.
        $this->gateway = new FakeConnectGateway();
        $this->app->singleton(EscrowPaymentService::class, function () {
            $service = new EscrowPaymentService();
            $service->registerGateway('stripe_connect', $this->gateway);

            return $service;
        });
    }

    private function hold(string $ref, array $overrides = []): SettlementHold
    {
        return SettlementHold::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'connected_account_id' => $this->account->id,
            'reference' => $ref,
            'amount' => 100.0,
            'currency' => 'GBP',
            'status' => SettlementHold::STATUS_HELD,
            'held_at' => now()->subDays(10),
            'hold_expires_at' => now()->addDays(80),
        ], $overrides));
    }

    public function test_it_runs_cleanly_with_no_holds(): void
    {
        $this->artisan('settlements:process-holds')
            ->expectsOutputToContain('Released 0 by rule')
            ->assertExitCode(0);
    }

    public function test_it_fires_a_due_elapsed_days_rule(): void
    {
        SettlementRule::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Auto release after 7 days',
            'condition_type' => SettlementRule::CONDITION_ELAPSED_DAYS,
            'config' => ['days' => 7],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ]);

        $hold = $this->hold('REF-DUE');

        $this->artisan('settlements:process-holds')->assertExitCode(0);

        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertCount(1, $this->gateway->payouts);
    }

    public function test_it_force_releases_a_hold_at_the_stripe_limit(): void
    {
        $hold = $this->hold('REF-EXPIRING', ['hold_expires_at' => now()->addDay()]);

        $this->artisan('settlements:process-holds')
            ->expectsOutputToContain('1 forced at the Stripe limit')
            ->assertExitCode(0);

        $this->assertSame(SettlementHold::STATUS_RELEASED, $hold->fresh()->status);
    }

    public function test_a_dry_run_releases_nothing(): void
    {
        $this->hold('REF-EXPIRING', ['hold_expires_at' => now()->addDay()]);

        $this->artisan('settlements:process-holds', ['--dry-run' => true])
            ->expectsOutputToContain('nothing was released')
            ->assertExitCode(0);

        $this->assertCount(0, $this->gateway->payouts);
    }

    /**
     * A sweep where money failed to move must not report success, or a broken
     * payout path goes unnoticed until someone complains.
     */
    public function test_it_exits_non_zero_when_a_release_fails(): void
    {
        SettlementRule::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Auto release',
            'condition_type' => SettlementRule::CONDITION_ELAPSED_DAYS,
            'config' => ['days' => 1],
            'release_percentage' => 100,
            'priority' => 100,
            'is_active' => true,
        ]);

        $this->hold('REF-BROKEN', ['hold_expires_at' => now()->addDay()]);
        $this->account->update(['payout_schedule' => 'daily']);

        $this->artisan('settlements:process-holds')->assertExitCode(1);

        $this->assertCount(0, $this->gateway->payouts);
    }

    public function test_the_schedule_registers_the_sweep(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => $e->command . ' @ ' . $e->expression);

        $this->assertTrue(
            $events->contains(fn ($e) => str_contains($e, 'settlements:process-holds') && str_contains($e, '0 * * * *')),
            'The hold sweep should be scheduled hourly: ' . $events->implode(', ')
        );
    }
}
