<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Balance;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Transfer;

/**
 * Stripe Connect with conditional settlement.
 *
 * The buyer is charged directly on the seller's own connected account, and
 * that account is held on manual payouts. Money therefore sits in the seller's
 * Stripe balance, not Peppa's, until the rules engine triggers a payout.
 *
 * Accounts are created with controller properties rather than a legacy account
 * type, because no legacy type gives both halves of what a hold needs:
 *
 *   losses.payments       = stripe   Stripe covers unrecoverable negative
 *                                    balances, not Peppa.
 *   stripe_dashboard.type = none     The seller has no Stripe UI, so there is
 *                                    no surface from which to pay themselves
 *                                    out. Stripe documents that a full
 *                                    dashboard (Standard) account can still
 *                                    make its own manual payouts after the
 *                                    platform restricts its schedule, which
 *                                    would quietly defeat the hold.
 *   requirement_collection= stripe   Stripe runs KYC through hosted
 *                                    onboarding, so Peppa never handles
 *                                    seller identity documents or bank
 *                                    details.
 *   fees.payer            = account  The seller pays Stripe's processing fees.
 *
 * Verified against the test API, not just the docs: `express` is rejected
 * outright here with "your platform must collect fees and be liable for
 * negative balances or refunds and chargebacks", which is not in Stripe's
 * published list of unsupported combinations. `none` carries no such
 * restriction and is the only configuration that keeps losses off the
 * platform while denying the seller a way to release their own funds.
 *
 * Split payments work differently, because Stripe only pays a balance out to
 * the account that owns it: money in one party's balance can never be shared
 * with another. A split payment is therefore divided the moment it is paid.
 * The charge passes through the platform (separate charges and transfers)
 * and each share is transferred straight on into its participant's own
 * account, which is held on manual payouts exactly like a holding account.
 * Nothing is kept in Peppa's balance; a release pays each participant out
 * from their own. Stripe makes the platform liable for refunds and
 * chargebacks on every indirect charge, so participants are platform-liable
 * accounts.
 *
 * @see https://docs.stripe.com/connect/migrate-to-controller-properties
 * @see https://docs.stripe.com/connect/manual-payouts
 * @see https://docs.stripe.com/connect/separate-charges-and-transfers
 */
class StripeConnectGateway implements PaymentGatewayInterface
{
    /**
     * How long Stripe permits funds to sit in a connected account's balance on
     * manual payouts, by the seller's country. Everything not listed is 90
     * days. Exceeding it is a compliance breach, not a soft limit.
     *
     * @see https://docs.stripe.com/connect/manual-payouts
     */
    public const HOLDING_PERIOD_DAYS = [
        'TH' => 10,
        'US' => 730,
    ];

    public const DEFAULT_HOLDING_PERIOD_DAYS = 90;

    protected string $secretKey;
    protected string $publicKey;
    protected string $webhookSecret;
    protected string $connectWebhookSecret;

    /** Where Peppa's own Stripe account is registered; the fallback holding limit. */
    protected string $platformCountry;

    public function __construct(array $config)
    {
        $this->platformCountry = strtoupper($config['platform_country'] ?? 'GB');
        $this->secretKey = $config['secret_key'] ?? '';
        $this->publicKey = $config['public_key'] ?? '';
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->connectWebhookSecret = $config['connect_webhook_secret'] ?? '';

        Stripe::setApiKey($this->secretKey);
    }

    public function getName(): string
    {
        return 'Stripe Connect';
    }

    public function getKey(): string
    {
        return 'stripe_connect';
    }

    // ─── Holding periods ──────────────────────────────────────────────

    public function holdingPeriodDays(string $country): int
    {
        return self::HOLDING_PERIOD_DAYS[strtoupper($country)] ?? self::DEFAULT_HOLDING_PERIOD_DAYS;
    }

    public function platformCountry(): string
    {
        return $this->platformCountry;
    }

    /**
     * The latest moment a payment may still be sitting in the seller's balance.
     * The rules engine uses this to force a release before Stripe's limit.
     */
    public function holdExpiresAt(string $country, ?Carbon $chargedAt = null): Carbon
    {
        return ($chargedAt ?? now())->copy()->addDays($this->holdingPeriodDays($country));
    }

    // ─── Connected accounts ───────────────────────────────────────────

    /**
     * @return array{account_id: string, country: string, holding_period_days: int, charges_enabled: bool, payouts_enabled: bool}
     */
    public function createConnectedAccount(
        string $email,
        string $country,
        array $metadata = [],
        string $type = 'holding',
    ): array {
        $params = $type === 'recipient'
            ? [
                // A participant's share sits in its own balance until
                // released, so like a holding account it gets no dashboard to
                // pay itself out from. Indirect charges leave the platform
                // liable regardless, which Stripe requires these settings to
                // say.
                'controller' => [
                    'losses' => ['payments' => 'application'],
                    'fees' => ['payer' => 'application'],
                    'requirement_collection' => 'stripe',
                    'stripe_dashboard' => ['type' => 'none'],
                ],
                'capabilities' => [
                    'transfers' => ['requested' => true],
                ],
            ]
            : [
                'controller' => [
                    'losses' => ['payments' => 'stripe'],
                    'fees' => ['payer' => 'account'],
                    'requirement_collection' => 'stripe',
                    'stripe_dashboard' => ['type' => 'none'],
                ],
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
            ];

        $account = Account::create(array_merge($params, [
            'email' => $email,
            'country' => strtoupper($country),
            'metadata' => $metadata,
        ]));

        return [
            'account_id' => $account->id,
            'country' => $account->country,
            'holding_period_days' => $this->holdingPeriodDays($account->country),
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
        ];
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        $link = AccountLink::create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return ['url' => $link->url, 'expires_at' => $link->expires_at];
    }

    /**
     * Onboarding status, plus the two properties the settlement model depends
     * on. Check these before letting an account take money: a seller whose
     * losses fall on the platform breaks the risk model the design rests on.
     */
    public function getAccountStatus(string $accountId): array
    {
        $account = Account::retrieve($accountId);

        $controller = $account->controller ?? null;
        $lossesPayer = $controller->losses->payments ?? null;
        $dashboard = $controller->stripe_dashboard->type ?? null;

        return [
            'account_id' => $account->id,
            'country' => $account->country,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'details_submitted' => (bool) $account->details_submitted,
            'losses_payer' => $lossesPayer,
            'dashboard_type' => $dashboard,
            'platform_bears_losses' => $lossesPayer === 'application',
            'payout_schedule' => $account->settings->payouts->schedule->interval ?? null,
            'requirements_due' => $account->requirements->currently_due ?? [],
            'holding_period_days' => $this->holdingPeriodDays((string) $account->country),
        ];
    }

    /**
     * Hold the seller's funds by switching their account off automatic
     * payouts. Without this the balance pays out on Stripe's rolling schedule
     * and there is nothing to release.
     */
    public function setManualPayouts(string $accountId): array
    {
        $account = Account::update($accountId, [
            'settings' => [
                'payouts' => ['schedule' => ['interval' => 'manual']],
            ],
        ]);

        return [
            'account_id' => $account->id,
            'payout_schedule' => $account->settings->payouts->schedule->interval ?? null,
        ];
    }

    // ─── Taking payment (direct charge on the seller) ─────────────────

    /**
     * A direct charge: the payment is created on the connected account, so the
     * funds land in the seller's balance and the seller is the merchant of
     * record. Peppa's cut rides along as an application fee.
     *
     * @param string $accountId       The seller's connected account
     * @param int    $applicationFee  Peppa's fee, in minor units
     */
    public function createDirectCheckout(
        string $accountId,
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
        int $applicationFee = 0,
        array $metadata = [],
    ): array {
        $minor = $this->toMinorUnits($amount, $currency);

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => ['name' => 'Payment ' . $reference],
                    'unit_amount' => $minor,
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => $reference,
            'customer_email' => $customerEmail,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => array_merge($metadata, ['reference' => $reference]),
        ];

        if ($applicationFee > 0) {
            $params['payment_intent_data'] = ['application_fee_amount' => $applicationFee];
        }

        $session = Session::create($params, ['stripe_account' => $accountId]);

        return [
            'paymentUrl' => $session->url,
            'reference' => $reference,
            'session_id' => $session->id,
            'account_id' => $accountId,
        ];
    }

    // ─── Taking a split payment ───────────────────────────────────────

    /**
     * Collect a split payment. It is shared out to the participants as soon
     * as it succeeds; the transfer group ties every share back to it.
     */
    public function createPlatformCheckout(
        float $amount,
        string $currency,
        string $reference,
        string $transferGroup,
        ?string $customerEmail,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => ['name' => 'Payment ' . $reference],
                    'unit_amount' => $this->toMinorUnits($amount, $currency),
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => $reference,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_intent_data' => [
                'transfer_group' => $transferGroup,
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
        ];

        if ($customerEmail) {
            $params['customer_email'] = $customerEmail;
        }

        $session = Session::create($params);

        return [
            'session_id' => $session->id,
            'payment_url' => $session->url,
        ];
    }

    /**
     * The charge behind a platform payment intent. Transfers name it as their
     * source so each share is tied to the money that funded it.
     *
     * @return array{charge_id: ?string, amount: float, currency: string, status: string}
     */
    public function platformPayment(string $paymentIntentId): array
    {
        $intent = PaymentIntent::retrieve($paymentIntentId);

        return [
            'charge_id' => is_string($intent->latest_charge) ? $intent->latest_charge : ($intent->latest_charge->id ?? null),
            'amount' => $this->fromMinorUnits((int) $intent->amount_received, (string) $intent->currency),
            'currency' => strtoupper((string) $intent->currency),
            'status' => (string) $intent->status,
        ];
    }

    /**
     * Move one share of a split payment into its participant's account.
     *
     * The idempotency key makes a retry after a timeout safe: Stripe returns
     * the original transfer instead of sending the money twice.
     */
    public function createTransfer(
        string $destinationAccountId,
        float $amount,
        string $currency,
        string $transferGroup,
        ?string $sourceCharge,
        string $idempotencyKey,
        array $metadata = [],
    ): array {
        $params = [
            'amount' => $this->toMinorUnits($amount, $currency),
            'currency' => strtolower($currency),
            'destination' => $destinationAccountId,
            'transfer_group' => $transferGroup,
            'metadata' => $metadata,
        ];

        if ($sourceCharge) {
            $params['source_transaction'] = $sourceCharge;
        }

        $transfer = Transfer::create($params, ['idempotency_key' => $idempotencyKey]);

        return [
            'transfer_id' => $transfer->id,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ];
    }

    /**
     * Pull part of a share back from a participant's account, ahead of
     * refunding the payer.
     */
    public function reverseTransfer(string $transferId, float $amount, string $currency, string $idempotencyKey): array
    {
        $reversal = Transfer::createReversal($transferId, [
            'amount' => $this->toMinorUnits($amount, $currency),
        ], ['idempotency_key' => $idempotencyKey]);

        return ['reversal_id' => $reversal->id, 'amount' => $amount];
    }

    /** Refund part or all of a split payment's charge. */
    public function refundPlatformPayment(string $paymentIntentId, float $amount, string $currency, string $reason = ''): array
    {
        $params = [
            'payment_intent' => $paymentIntentId,
            'amount' => $this->toMinorUnits($amount, $currency),
            'metadata' => ['reason' => $reason],
        ];

        $refund = Refund::create($params);

        return [
            'status' => $refund->status,
            'reference' => $refund->id,
            'message' => 'Refund ' . $refund->status,
        ];
    }

    // ─── Releasing (the payout the rules engine triggers) ─────────────

    public function getBalance(string $accountId): array
    {
        // Balance is a singleton resource: retrieve() takes request options as
        // its only argument. Passing params first silently drops the
        // stripe_account option and returns the platform's balance instead of
        // the seller's, which reads as plausible and is entirely wrong.
        $balance = Balance::retrieve(['stripe_account' => $accountId]);

        $available = [];
        foreach ($balance->available as $entry) {
            $available[strtoupper($entry->currency)] = $entry->amount;
        }

        $pending = [];
        foreach ($balance->pending as $entry) {
            $pending[strtoupper($entry->currency)] = $entry->amount;
        }

        return ['available' => $available, 'pending' => $pending];
    }

    /**
     * Pay the held funds out to the seller's bank. Partial releases are just a
     * smaller amount; Stripe only ever pays a balance out to that account's
     * own external account, so a payout cannot be redirected elsewhere.
     */
    public function createPayout(
        string $accountId,
        float $amount,
        string $currency,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): array {
        $options = ['stripe_account' => $accountId];

        if ($idempotencyKey) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        $payout = Payout::create([
            'amount' => $this->toMinorUnits($amount, $currency),
            'currency' => strtolower($currency),
            'metadata' => $metadata,
        ], $options);

        return [
            'payout_id' => $payout->id,
            'status' => $payout->status,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'arrival_date' => $payout->arrival_date,
        ];
    }

    /**
     * Payouts Stripe has made on a seller account, for reconciliation.
     *
     * Paged through to the end rather than taking the first 100: a partial
     * list reads as money Stripe never paid out, which is exactly the kind of
     * false discrepancy that teaches people to ignore the report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPayouts(string $accountId, int $pageSize = 100): array
    {
        $payouts = [];
        $startingAfter = null;

        do {
            $params = ['limit' => $pageSize];

            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $page = Payout::all($params, ['stripe_account' => $accountId]);

            foreach ($page->data as $payout) {
                $payouts[] = [
                    'id' => $payout->id,
                    'amount' => $this->fromMinorUnits((int) $payout->amount, (string) $payout->currency),
                    'currency' => strtoupper((string) $payout->currency),
                    'status' => $payout->status,
                    'arrival_date' => $payout->arrival_date,
                    'created' => $payout->created,
                    'metadata' => $payout->metadata ? $payout->metadata->toArray() : [],
                ];
                $startingAfter = $payout->id;
            }
        } while ($page->has_more);

        return $payouts;
    }

    /**
     * A session for Stripe's embedded components.
     *
     * Sellers have no Stripe dashboard under this configuration, and Peppa is
     * barred from touching their bank details or identity data. Embedding
     * Stripe's own components is therefore the only way a seller can manage
     * those things, short of Peppa building a UI it has no permission to
     * back.
     *
     * @param array<int, string> $components
     */
    public function createAccountSession(string $accountId, array $components = ['payouts', 'account_management']): array
    {
        $enabled = [];

        foreach ($components as $component) {
            $enabled[$component] = ['enabled' => true];
        }

        $session = \Stripe\AccountSession::create([
            'account' => $accountId,
            'components' => $enabled,
        ]);

        return [
            'client_secret' => $session->client_secret,
            'expires_at' => $session->expires_at,
            'components' => array_keys($enabled),
        ];
    }

    // ─── PaymentGatewayInterface ──────────────────────────────────────

    /**
     * Not usable without a seller: a direct charge has to name the connected
     * account it lands on. Call createDirectCheckout() instead.
     */
    public function createPaymentLink(
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        array $metadata = []
    ): array {
        throw new \BadMethodCallException(
            'Stripe Connect charges are made on a seller account. Use createDirectCheckout().'
        );
    }

    /**
     * @param string|null $accountId The seller the charge was made on. Direct
     *                               charges do not exist on the platform
     *                               account, so this is required.
     */
    public function verifyPayment(string $reference, ?string $accountId = null): array
    {
        if (!$accountId) {
            throw new \InvalidArgumentException('A connected account id is required to verify a direct charge.');
        }

        $session = Session::retrieve($reference, ['stripe_account' => $accountId]);

        return [
            'status' => $session->payment_status === 'paid' ? 'success' : (string) $session->payment_status,
            'amount' => $this->fromMinorUnits((int) $session->amount_total, (string) $session->currency),
            'currency' => strtoupper((string) $session->currency),
            'reference' => $session->client_reference_id ?? $reference,
            'gateway_response' => $session->toArray(),
        ];
    }

    /**
     * Refunds come out of the seller's balance, since that is where the money
     * is. Pass the connected account the charge was made on.
     */
    public function processRefund(string $transactionReference, float $amount, string $reason = '', ?string $accountId = null): array
    {
        if (!$accountId) {
            throw new \InvalidArgumentException('A connected account id is required to refund a direct charge.');
        }

        $params = ['payment_intent' => $transactionReference];

        if ($amount > 0) {
            $params['amount'] = (int) round($amount * 100);
        }

        if ($reason !== '') {
            $params['reason'] = 'requested_by_customer';
        }

        $refund = Refund::create($params, ['stripe_account' => $accountId]);

        return [
            'status' => $refund->status,
            'reference' => $refund->id,
            'message' => 'Refund ' . $refund->status,
        ];
    }

    /**
     * Connect sends account and payout events to a separate endpoint secret
     * from ordinary platform events, so prefer that one when it is set.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $header = $headers['stripe-signature'] ?? '';

        if (is_array($header)) {
            $header = $header[0] ?? '';
        }

        if ($header === '') {
            return false;
        }

        foreach ([$this->connectWebhookSecret, $this->webhookSecret] as $secret) {
            if ($secret === '') {
                continue;
            }

            try {
                \Stripe\Webhook::constructEvent($rawPayload, (string) $header, $secret);
                return true;
            } catch (\Exception $e) {
                // Try the next secret before giving up.
            }
        }

        Log::warning('Stripe Connect webhook signature rejected');

        return false;
    }

    // ─── Amounts ──────────────────────────────────────────────────────

    /**
     * Stripe takes amounts in the currency's smallest unit. The zero-decimal
     * currencies take whole units instead, and multiplying those by 100 would
     * overcharge by a hundred times.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    public const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    protected function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    protected function fromMinorUnits(int $amount, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float) $amount;
        }

        return $amount / 100;
    }
}
