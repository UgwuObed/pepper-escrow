<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\SettlementHold;
use App\PaymentGateways\StripeConnectGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checks Peppa's record of what was released against Stripe's record of what
 * was paid out.
 *
 * The money lives in Stripe, so Stripe is the truth. Our holds table is a
 * claim about that truth, and a claim that has drifted is how a seller ends
 * up paid twice, or a buyer told their refund went out when it did not.
 *
 * Reconciliation is read-only on purpose. It reports drift; it never
 * "corrects" a balance by writing to one side, because the right correction
 * depends on which side is wrong and that is a human decision.
 */
class SettlementReconciliationService
{
    /** Tolerance for rounding, in major units. */
    public const TOLERANCE = 0.01;

    public const OK = 'ok';
    public const UNDER_PAID = 'under_paid';
    public const OVER_PAID = 'over_paid';
    public const ORPHAN_PAYOUT = 'orphan_payout';
    public const STALE_HOLD = 'stale_hold';
    public const ACCOUNT_DRIFTED = 'account_drifted';
    public const UNREACHABLE = 'unreachable';

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
     * Reconcile every seller account belonging to a merchant.
     *
     * @return array{
     *   checked: int, accounts: int, discrepancies: array<int, array<string, mixed>>,
     *   totals: array{held: float, released: float, paid_out: float}
     * }
     */
    public function reconcileMerchant(int $merchantId): array
    {
        $accounts = ConnectedAccount::byMerchant($merchantId)->get();

        $discrepancies = [];
        $checked = 0;
        $totals = ['held' => 0.0, 'released' => 0.0, 'paid_out' => 0.0];

        foreach ($accounts as $account) {
            $result = $this->reconcileAccount($account);

            $checked += $result['checked'];
            $totals['held'] += $result['totals']['held'];
            $totals['released'] += $result['totals']['released'];
            $totals['paid_out'] += $result['totals']['paid_out'];

            $discrepancies = array_merge($discrepancies, $result['discrepancies']);
        }

        return [
            'checked' => $checked,
            'accounts' => $accounts->count(),
            'discrepancies' => $discrepancies,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
        ];
    }

    /**
     * Reconcile one seller account: our holds against Stripe's payouts.
     *
     * @return array{checked: int, discrepancies: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function reconcileAccount(ConnectedAccount $account): array
    {
        $holds = SettlementHold::where('connected_account_id', $account->id)->get();

        $totals = [
            'held' => round((float) $holds->sum(fn ($h) => $h->remaining()), 2),
            'released' => round((float) $holds->sum('amount_released'), 2),
            'paid_out' => 0.0,
        ];

        try {
            $payouts = $this->stripePayouts($account);
        } catch (\Throwable $e) {
            Log::error('Reconciliation could not reach Stripe', [
                'account' => $account->account_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'checked' => $holds->count(),
                'totals' => $totals,
                'discrepancies' => [[
                    'type' => self::UNREACHABLE,
                    'account_id' => $account->account_id,
                    'detail' => $e->getMessage(),
                ]],
            ];
        }

        $totals['paid_out'] = round($payouts->sum('amount'), 2);

        $discrepancies = [];

        // An account that has drifted out of the holding configuration is a
        // discrepancy in its own right: funds in it are no longer under our
        // control even if every number currently agrees.
        if ($account->details_submitted && !$account->canHoldFunds()) {
            $discrepancies[] = [
                'type' => self::ACCOUNT_DRIFTED,
                'account_id' => $account->account_id,
                'detail' => implode(' ', $account->holdBlockers()),
            ];
        }

        $matchedPayouts = [];

        foreach ($holds as $hold) {
            $ours = round((float) $hold->amount_released, 2);
            $theirs = round($payouts->where('reference', $hold->reference)->sum('amount'), 2);

            foreach ($payouts->where('reference', $hold->reference) as $p) {
                $matchedPayouts[] = $p['id'];
            }

            $delta = round($theirs - $ours, 2);

            if (abs($delta) > self::TOLERANCE) {
                $discrepancies[] = [
                    // Over-paid is the dangerous one: Stripe moved more than
                    // our records account for, so somebody has money we did
                    // not authorise.
                    'type' => $delta > 0 ? self::OVER_PAID : self::UNDER_PAID,
                    'account_id' => $account->account_id,
                    'reference' => $hold->reference,
                    'hold_id' => $hold->id,
                    'ours' => $ours,
                    'stripe' => $theirs,
                    'delta' => $delta,
                    'currency' => $hold->currency,
                ];
            }

            if ($hold->isReleasable() && $hold->hasExpired()) {
                $discrepancies[] = [
                    'type' => self::STALE_HOLD,
                    'account_id' => $account->account_id,
                    'reference' => $hold->reference,
                    'hold_id' => $hold->id,
                    'detail' => sprintf('Held past the Stripe limit (expired %s)',
                        $hold->hold_expires_at?->toDateString() ?? 'unknown'),
                ];
            }
        }

        // A payout Stripe made that no hold claims. Either someone paid out
        // by hand in the dashboard, or a release was recorded against the
        // wrong reference.
        foreach ($payouts as $payout) {
            if (in_array($payout['id'], $matchedPayouts, true)) {
                continue;
            }

            $discrepancies[] = [
                'type' => self::ORPHAN_PAYOUT,
                'account_id' => $account->account_id,
                'payout_id' => $payout['id'],
                'amount' => $payout['amount'],
                'currency' => $payout['currency'],
                'reference' => $payout['reference'],
                'detail' => 'Stripe paid this out but no hold records it',
            ];
        }

        return [
            'checked' => $holds->count(),
            'totals' => $totals,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Payouts Stripe has made on this account, flattened to what we compare.
     *
     * Only payouts that actually moved money count. A failed or cancelled
     * payout would otherwise look like money we released that Stripe lost.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function stripePayouts(ConnectedAccount $account): Collection
    {
        $raw = $this->gateway()->listPayouts($account->account_id);

        return collect($raw)
            ->filter(fn ($p) => in_array($p['status'], ['paid', 'pending', 'in_transit'], true))
            ->map(fn ($p) => [
                'id' => $p['id'],
                'amount' => $p['amount'],
                'currency' => $p['currency'],
                'status' => $p['status'],
                'reference' => $p['metadata']['reference'] ?? null,
            ])
            ->values();
    }

    /**
     * A one-line verdict for the admin screen.
     */
    public function summarise(array $report): string
    {
        $count = count($report['discrepancies']);

        if ($count === 0) {
            return sprintf('%d holds reconciled, no discrepancies.', $report['checked']);
        }

        $byType = collect($report['discrepancies'])->countBy('type')
            ->map(fn ($n, $type) => "{$n} " . str_replace('_', ' ', $type))
            ->implode(', ');

        return sprintf('%d holds checked, %d discrepancies: %s.', $report['checked'], $count, $byType);
    }
}
