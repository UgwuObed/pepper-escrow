<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\SettlementHold;
use App\Models\SettlementTransfer;
use App\PaymentGateways\StripeConnectGateway;
use Illuminate\Support\Facades\Log;

/**
 * What a seller can see about their own money.
 *
 * Sellers have no Stripe dashboard under this configuration, so if Peppa
 * does not show them their balance, their payouts and why money is being
 * held, nobody does. That makes this a required part of the product rather
 * than a nicety.
 */
class SellerDashboardService
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
     * Everything a seller needs on one screen.
     *
     * Stripe being unreachable degrades the balance and payout sections
     * rather than failing the whole page: the held-funds view comes from our
     * own records and is the part a seller is usually chasing.
     *
     * @return array<string, mixed>
     */
    public function forAccount(ConnectedAccount $account): array
    {
        return [
            'seller' => [
                'account_id' => $account->account_id,
                'email' => $account->seller_email,
                'country' => $account->country,
            ],
            'onboarding' => $this->onboarding($account),
            'holds' => $this->holds($account),
            'received' => $this->received($account),
            'balance' => $this->balance($account),
            'payouts' => $this->payouts($account),
        ];
    }

    /**
     * Shares of pooled releases sent to this account, newest first. A
     * recipient has no holds of its own, so this is what it is waiting on
     * and what has arrived.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function received(ConnectedAccount $account, int $limit = 50): array
    {
        return SettlementTransfer::where('connected_account_id', $account->id)
            ->with('hold:id,reference')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SettlementTransfer $t) => [
                'reference' => $t->hold?->reference,
                'as' => $t->destination,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'status' => $t->status,
                'transfer_id' => $t->transfer_id,
                'at' => $t->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Where the seller is in Stripe's onboarding, in words rather than flags.
     */
    protected function onboarding(ConnectedAccount $account): array
    {
        $outstanding = $account->requirements_due ?? [];

        $state = match (true) {
            $account->charges_enabled && $account->payouts_enabled => 'complete',
            $account->details_submitted => 'in_review',
            default => 'incomplete',
        };

        return [
            'state' => $state,
            'can_take_payments' => (bool) $account->charges_enabled,
            'can_be_paid_out' => (bool) $account->payouts_enabled,
            'outstanding_requirements' => array_values($outstanding),
            'message' => match ($state) {
                'complete' => 'Your account is fully set up.',
                'in_review' => 'Stripe is reviewing your details. Nothing is needed from you right now.',
                default => 'Stripe still needs some details before you can be paid.',
            },
        ];
    }

    /**
     * The seller's own view of what is being held and why.
     */
    protected function holds(ConnectedAccount $account): array
    {
        $holds = SettlementHold::where('connected_account_id', $account->id)
            ->orderByDesc('held_at')
            ->limit(50)
            ->get();

        $open = $holds->filter(fn ($h) => $h->isReleasable());

        return [
            'currently_held' => round((float) $open->sum(fn ($h) => $h->remaining()), 2),
            'released_to_date' => round((float) $holds->sum('amount_released'), 2),
            'disputed_count' => $holds->where('status', SettlementHold::STATUS_DISPUTED)->count(),
            'items' => $holds->map(fn (SettlementHold $h) => [
                'reference' => $h->reference,
                'amount' => (float) $h->amount,
                'remaining' => $h->remaining(),
                'currency' => $h->currency,
                'status' => $h->status,
                'held_at' => $h->held_at?->toIso8601String(),
                'expected_by' => $h->hold_expires_at?->toIso8601String(),
                // Sellers chase payments, so say plainly where each one is.
                'explanation' => $this->explain($h),
            ])->values()->all(),
        ];
    }

    protected function explain(SettlementHold $hold): string
    {
        return match ($hold->status) {
            SettlementHold::STATUS_RELEASED => 'Paid out to your bank.',
            SettlementHold::STATUS_PART_RELEASED => sprintf(
                '%s %s paid out so far, the rest is still held.',
                $hold->currency, number_format((float) $hold->amount_released, 2)
            ),
            SettlementHold::STATUS_DISPUTED => 'On hold while a dispute is resolved.',
            SettlementHold::STATUS_REFUNDED => 'Refunded to the buyer.',
            default => 'Held until the release condition is met.',
        };
    }

    protected function balance(ConnectedAccount $account): array
    {
        try {
            $balance = $this->gateway()->getBalance($account->account_id);

            return [
                'available' => $this->toMajor($balance['available']),
                'pending' => $this->toMajor($balance['pending']),
                'source' => 'stripe',
            ];
        } catch (\Throwable $e) {
            Log::warning('Seller balance unavailable', [
                'account' => $account->account_id,
                'error' => $e->getMessage(),
            ]);

            return ['available' => null, 'pending' => null, 'source' => 'unavailable'];
        }
    }

    protected function payouts(ConnectedAccount $account, int $limit = 20): array
    {
        try {
            return collect($this->gateway()->listPayouts($account->account_id))
                ->sortByDesc('created')
                ->take($limit)
                ->map(fn ($p) => [
                    'id' => $p['id'],
                    'amount' => $p['amount'],
                    'currency' => $p['currency'],
                    'status' => $p['status'],
                    'reference' => $p['metadata']['reference'] ?? null,
                    'arriving' => $p['arrival_date'] ? date('c', $p['arrival_date']) : null,
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Seller payout history unavailable', [
                'account' => $account->account_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * getBalance reports minor units; sellers read money in major ones.
     *
     * @param array<string, int> $amounts
     * @return array<string, float>
     */
    protected function toMajor(array $amounts): array
    {
        $out = [];

        foreach ($amounts as $currency => $minor) {
            $out[$currency] = in_array($currency, StripeConnectGateway::ZERO_DECIMAL_CURRENCIES, true)
                ? (float) $minor
                : $minor / 100;
        }

        return $out;
    }
}
