<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\PaymentGateways\StripeConnectGateway;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SellerOnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string $token;
    private OnboardFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');

        $this->merchant = Merchant::factory()->create([
            'business_name' => 'Acme Marketplace',
            'seller_onboarding_enabled' => true,
        ]);
        $this->token = $this->merchant->sellerOnboardingToken();

        $this->gateway = new OnboardFakeGateway();
        $this->app->singleton(EscrowPaymentService::class, function () {
            $s = new EscrowPaymentService();
            $s->registerGateway('stripe_connect', $this->gateway);

            return $s;
        });
    }

    // ─── Access ───────────────────────────────────────────────────────

    public function test_the_page_renders_for_a_valid_token(): void
    {
        $this->get("/connect/{$this->token}")
            ->assertStatus(200)
            ->assertSee('Acme Marketplace')
            ->assertSee('Set up your payouts');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/connect/' . str_repeat('x', 32))->assertStatus(404);
    }

    /**
     * The page creates Stripe accounts, so a partner opts in rather than
     * discovering one already exists on a guessable URL.
     */
    public function test_a_partner_who_has_not_opted_in_has_no_page(): void
    {
        $this->merchant->update(['seller_onboarding_enabled' => false]);

        $this->get("/connect/{$this->token}")->assertStatus(404);
        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'GB'])->assertStatus(404);
    }

    // ─── Starting ─────────────────────────────────────────────────────

    public function test_submitting_creates_an_account_and_redirects_to_stripe(): void
    {
        $response = $this->post("/connect/{$this->token}", [
            'email' => 'Seller@Example.com',
            'country' => 'GB',
        ]);

        $response->assertRedirect('https://connect.stripe.com/setup/fake');

        // Stored lower-cased, so a seller returning with different casing is
        // recognised rather than given a second account.
        $this->assertDatabaseHas('connected_accounts', [
            'merchant_id' => $this->merchant->id,
            'seller_email' => 'seller@example.com',
            'country' => 'GB',
        ]);
    }

    public function test_payouts_are_armed_before_the_seller_can_be_paid(): void
    {
        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'GB']);

        $this->assertContains('acct_fake_1', $this->gateway->armed);
    }

    public function test_a_bad_email_is_rejected_with_a_readable_message(): void
    {
        $this->post("/connect/{$this->token}", ['email' => 'not-an-email', 'country' => 'GB'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, ConnectedAccount::count());
    }

    public function test_an_unsupported_country_is_rejected(): void
    {
        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'NG'])
            ->assertSessionHasErrors('country');

        $this->assertSame(0, ConnectedAccount::count());
    }

    /**
     * Someone who abandons the flow and comes back should land in the same
     * account, not collect a second one.
     */
    public function test_returning_with_the_same_email_reuses_the_account(): void
    {
        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'GB']);
        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'GB']);

        $this->assertSame(1, ConnectedAccount::where('seller_email', 'a@b.com')->count());
    }

    public function test_an_already_active_seller_is_told_so_rather_than_sent_round_again(): void
    {
        ConnectedAccount::create([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe', 'account_id' => 'acct_done',
            'seller_email' => 'done@example.com', 'country' => 'GB',
            'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true,
            'payout_schedule' => 'manual', 'losses_payer' => 'stripe', 'dashboard_type' => 'none',
            'holding_period_days' => 90,
        ]);

        $this->post("/connect/{$this->token}", ['email' => 'done@example.com', 'country' => 'GB'])
            ->assertStatus(200)
            ->assertSee('already set up');
    }

    public function test_stripe_failing_shows_a_message_rather_than_an_error_page(): void
    {
        $this->gateway->throw = true;

        $this->post("/connect/{$this->token}", ['email' => 'a@b.com', 'country' => 'GB'])
            ->assertSessionHasErrors('email');
    }

    // ─── Returning from Stripe ────────────────────────────────────────

    private function account(array $overrides = []): ConnectedAccount
    {
        return ConnectedAccount::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'provider' => 'stripe', 'account_id' => 'acct_fake_1',
            'seller_email' => 'a@b.com', 'country' => 'GB',
            'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
            'payout_schedule' => 'manual', 'losses_payer' => 'stripe', 'dashboard_type' => 'none',
            'holding_period_days' => 90,
        ], $overrides));
    }

    public function test_an_approved_seller_is_told_they_are_set_up(): void
    {
        $this->account();
        $this->gateway->status = ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true];

        $this->get("/connect/{$this->token}/done/acct_fake_1")
            ->assertStatus(200)
            ->assertSee('all set');
    }

    /**
     * Coming back from Stripe only means the seller left the flow, not that
     * Stripe is satisfied, so the state is synced rather than assumed.
     */
    public function test_a_seller_awaiting_review_is_told_nothing_is_needed(): void
    {
        $this->account();
        $this->gateway->status = ['charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => true];

        $this->get("/connect/{$this->token}/done/acct_fake_1")
            ->assertStatus(200)
            ->assertSee('Nothing is needed from you right now');
    }

    public function test_an_unfinished_seller_sees_what_is_outstanding(): void
    {
        $this->account();
        $this->gateway->status = [
            'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
            'requirements_due' => ['individual.dob.day', 'individual.address.city'],
        ];

        $this->get("/connect/{$this->token}/done/acct_fake_1")
            ->assertStatus(200)
            ->assertSee('Not quite finished')
            ->assertSee('Dob day');
    }

    public function test_an_expired_link_mints_a_new_one(): void
    {
        $this->account();

        $this->get("/connect/{$this->token}/refresh/acct_fake_1")
            ->assertRedirect('https://connect.stripe.com/setup/fake');
    }

    public function test_another_merchants_account_cannot_be_reached_through_this_token(): void
    {
        $other = Merchant::factory()->create(['seller_onboarding_enabled' => true]);
        ConnectedAccount::create([
            'merchant_id' => $other->id, 'provider' => 'stripe', 'account_id' => 'acct_other',
            'seller_email' => 'x@y.com', 'country' => 'GB', 'holding_period_days' => 90,
        ]);

        $this->get("/connect/{$this->token}/done/acct_other")->assertStatus(404);
    }
}

class OnboardFakeGateway extends StripeConnectGateway
{
    public array $armed = [];
    public array $status = [];
    public bool $throw = false;
    private int $n = 0;

    public function __construct()
    {
        parent::__construct(['secret_key' => 'sk_test_fake']);
    }

    public function createConnectedAccount(string $email, string $country, array $metadata = []): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Stripe is unavailable');
        }

        $this->n++;

        return [
            'account_id' => 'acct_fake_' . $this->n,
            'country' => strtoupper($country),
            'holding_period_days' => $this->holdingPeriodDays($country),
            'charges_enabled' => false,
            'payouts_enabled' => false,
        ];
    }

    public function setManualPayouts(string $accountId): array
    {
        $this->armed[] = $accountId;

        return ['account_id' => $accountId, 'payout_schedule' => 'manual'];
    }

    public function getAccountStatus(string $accountId): array
    {
        return array_merge([
            'account_id' => $accountId, 'country' => 'GB',
            'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
            'losses_payer' => 'stripe', 'dashboard_type' => 'none',
            'payout_schedule' => 'manual', 'requirements_due' => [],
            'holding_period_days' => 90,
        ], $this->status);
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Stripe is unavailable');
        }

        return ['url' => 'https://connect.stripe.com/setup/fake', 'expires_at' => time() + 300];
    }
}
