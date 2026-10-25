<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\PaymentGateways\StripeConnectGateway;
use Illuminate\Support\Facades\Log;

/**
 * Seller onboarding and account state for conditional settlement.
 *
 * Stripe is the source of truth for account state; this mirrors it locally so
 * a charge can be refused without a round trip, and so an operator can see
 * why a seller is not ready.
 */
class ConnectedAccountService
{
    protected EscrowPaymentService $paymentService;

    public function __construct(EscrowPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    protected function gateway(): StripeConnectGateway
    {
        $gateway = $this->paymentService->getGateway('stripe_connect');

        if (!$gateway instanceof StripeConnectGateway) {
            throw new \RuntimeException('Stripe Connect is not configured.');
        }

        return $gateway;
    }

    /**
     * Create the seller's Stripe account and arm the hold straight away.
     *
     * Payouts are set to manual in the same breath as creation on purpose: an
     * account that takes a payment before being armed pays that payment
     * straight out on Stripe's rolling schedule, and there is no way to pull
     * it back.
     */
    public function onboard(Merchant $merchant, string $sellerEmail, string $country): ConnectedAccount
    {
        $gateway = $this->gateway();

        $created = $gateway->createConnectedAccount($sellerEmail, $country, [
            'merchant_id' => (string) $merchant->id,
        ]);

        $gateway->setManualPayouts($created['account_id']);

        $account = ConnectedAccount::create([
            'merchant_id' => $merchant->id,
            'provider' => 'stripe',
            'account_id' => $created['account_id'],
            'seller_email' => $sellerEmail,
            'country' => strtoupper($country),
            'holding_period_days' => $created['holding_period_days'],
        ]);

        return $this->syncStatus($account);
    }

    /**
     * A fresh onboarding link. These expire in minutes, so generate one per
     * visit rather than storing it.
     */
    public function onboardingLink(ConnectedAccount $account): array
    {
        return $this->gateway()->createOnboardingLink(
            $account->account_id,
            config('gateways.stripe_connect.onboarding_refresh_url') ?: url('/connect/refresh'),
            config('gateways.stripe_connect.onboarding_return_url') ?: url('/connect/return'),
        );
    }


    /**
     * A session for Stripe's embedded components, so a seller can manage the
     * things Peppa has no permission to touch.
     *
     * @param array<int, string> $components
     */
    public function embeddedSession(ConnectedAccount $account, array $components): array
    {
        return $this->gateway()->createAccountSession($account->account_id, $components);
    }

    /**
     * Pull the account's current state from Stripe into our copy.
     */
    public function syncStatus(ConnectedAccount $account): ConnectedAccount
    {
        $status = $this->gateway()->getAccountStatus($account->account_id);

        $account->update([
            'charges_enabled' => $status['charges_enabled'],
            'payouts_enabled' => $status['payouts_enabled'],
            'details_submitted' => $status['details_submitted'],
            'payout_schedule' => $status['payout_schedule'],
            'losses_payer' => $status['losses_payer'],
            'dashboard_type' => $status['dashboard_type'],
            'holding_period_days' => $status['holding_period_days'],
            'requirements_due' => $status['requirements_due'],
            'status_synced_at' => now(),
        ]);

        // An account that drifts out of the holding configuration is a
        // standing risk, not a one-off: funds already sitting in it may no
        // longer be under our control.
        if ($account->details_submitted && !$account->canHoldFunds()) {
            Log::warning('Connected account cannot hold funds', [
                'account_id' => $account->account_id,
                'blockers' => $account->holdBlockers(),
            ]);
        }

        return $account->fresh();
    }

    /**
     * Re-arm an account whose payout schedule has drifted off manual.
     */
    public function reassertHold(ConnectedAccount $account): ConnectedAccount
    {
        $this->gateway()->setManualPayouts($account->account_id);

        return $this->syncStatus($account);
    }

    /**
     * Stripe tells us when an account's state changes. Without this the local
     * copy goes stale and we start refusing sellers who are actually ready,
     * or accepting ones who are not.
     */
    public function handleAccountUpdated(array $payload): ?ConnectedAccount
    {
        $accountId = $payload['account'] ?? ($payload['data']['object']['id'] ?? null);

        if (!$accountId) {
            return null;
        }

        $account = ConnectedAccount::where('account_id', $accountId)->first();

        if (!$account) {
            Log::info('account.updated for an account we do not track', ['account_id' => $accountId]);
            return null;
        }

        return $this->syncStatus($account);
    }
}
