<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use Tests\TestCase;

/**
 * The account-state rules that decide whether a hold is actually safe. These
 * are pure model logic, so they need no Stripe calls.
 */
class ConnectedAccountHoldTest extends TestCase
{
    private function account(array $overrides = []): ConnectedAccount
    {
        return new ConnectedAccount(array_merge([
            'merchant_id' => 1,
            'provider' => 'stripe',
            'account_id' => 'acct_test',
            'seller_email' => 'seller@example.com',
            'country' => 'GB',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'payout_schedule' => 'manual',
            'losses_payer' => 'stripe',
            'dashboard_type' => 'none',
            'holding_period_days' => 90,
        ], $overrides));
    }

    public function test_a_correctly_configured_account_can_hold(): void
    {
        $this->assertTrue($this->account()->canHoldFunds());
        $this->assertSame([], $this->account()->holdBlockers());
    }

    public function test_automatic_payouts_block_the_hold(): void
    {
        $account = $this->account(['payout_schedule' => 'daily']);

        $this->assertFalse($account->canHoldFunds());
        $this->assertStringContainsString('not set to manual', $account->holdBlockers()[0]);
    }

    public function test_platform_bearing_losses_blocks_the_hold(): void
    {
        $account = $this->account(['losses_payer' => 'application']);

        $this->assertFalse($account->canHoldFunds());
        $this->assertStringContainsString('Losses fall on the platform', implode(' ', $account->holdBlockers()));
    }

    /**
     * A full dashboard lets the seller pay themselves out even when the
     * platform restricts the schedule, so the funds are not really held.
     */
    public function test_a_full_dashboard_blocks_the_hold(): void
    {
        $account = $this->account(['dashboard_type' => 'full']);

        $this->assertFalse($account->canHoldFunds());
        $this->assertStringContainsString('could pay themselves out', implode(' ', $account->holdBlockers()));
    }

    /**
     * Express is rejected by Stripe for this controller combination anyway,
     * but if one ever appears it still hands the seller a payout surface.
     */
    public function test_an_express_dashboard_blocks_the_hold(): void
    {
        $account = $this->account(['dashboard_type' => 'express']);

        $this->assertFalse($account->canHoldFunds());
        $this->assertStringContainsString('could pay themselves out', implode(' ', $account->holdBlockers()));
    }

    public function test_charges_not_yet_enabled_blocks_the_hold(): void
    {
        $account = $this->account(['charges_enabled' => false]);

        $this->assertFalse($account->canHoldFunds());
        $this->assertStringContainsString('has not enabled charges', $account->holdBlockers()[0]);
    }

    public function test_every_blocker_is_reported_at_once(): void
    {
        $account = $this->account([
            'charges_enabled' => false,
            'payout_schedule' => 'daily',
            'losses_payer' => 'application',
            'dashboard_type' => 'full',
        ]);

        $this->assertCount(4, $account->holdBlockers());
    }
}
