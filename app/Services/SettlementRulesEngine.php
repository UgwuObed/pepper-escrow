<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\HoldEvent;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\PaymentGateways\StripeConnectGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides when held money is released.
 *
 * Stripe holds the funds in the seller's balance and will pay them out when
 * told; nothing in Stripe decides when. That decision lives here.
 *
 * Three conditions release a hold:
 *   buyer_confirmation  the buyer says they got what they paid for
 *   elapsed_days        a timer runs out with no dispute
 *   manual_approval     a human approves it
 *
 * A dispute freezes the hold and no rule fires until it is resolved. Stripe's
 * per-country holding limit overrides everything: a hold at its deadline is
 * released regardless, because overrunning it is a compliance breach.
 */
class SettlementRulesEngine
{
    /** Release this many days before Stripe's deadline rather than on it. */
    public const EXPIRY_SAFETY_DAYS = 2;

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

    // ─── Opening a hold ───────────────────────────────────────────────

    /**
     * Record a payment that has landed in a seller's balance.
     *
     * The deadline comes from the seller's country, not ours: Stripe measures
     * the holding period against the account the money sits in.
     */
    public function hold(
        ConnectedAccount $account,
        string $reference,
        float $amount,
        string $currency = 'GBP',
        ?string $paymentIntentId = null,
        ?int $transactionId = null,
    ): SettlementHold {
        $expiresAt = $this->gateway()->holdExpiresAt($account->country);

        $hold = SettlementHold::create([
            'merchant_id' => $account->merchant_id,
            'connected_account_id' => $account->id,
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => SettlementHold::STATUS_HELD,
            'held_at' => now(),
            'hold_expires_at' => $expiresAt,
        ]);

        $this->record($hold, 'held', 'system', [
            'amount' => $amount,
            'note' => sprintf('Held until %s (%s, %d day limit)',
                $expiresAt->toDateString(), $account->country, $account->holding_period_days),
        ]);

        return $hold;
    }

    // ─── Conditions firing ────────────────────────────────────────────

    public function buyerConfirmed(SettlementHold $hold): SettlementHold
    {
        $this->record($hold, 'buyer_confirmed', 'buyer');

        return $this->evaluate($hold, SettlementRule::CONDITION_BUYER_CONFIRMATION);
    }

    public function approvedManually(SettlementHold $hold, string $actor): SettlementHold
    {
        $this->record($hold, 'approved', $actor);

        return $this->evaluate($hold, SettlementRule::CONDITION_MANUAL_APPROVAL);
    }

    public function raiseDispute(SettlementHold $hold, string $reason, string $actor = 'buyer'): SettlementHold
    {
        if (!$hold->isReleasable()) {
            return $hold;
        }

        $hold->update(['status' => SettlementHold::STATUS_DISPUTED]);
        $this->record($hold, 'dispute_raised', $actor, ['note' => $reason]);

        return $hold->fresh();
    }

    /**
     * @param string $outcome release | refund | resume
     */
    public function resolveDispute(SettlementHold $hold, string $outcome, string $actor): SettlementHold
    {
        if (!$hold->isFrozen()) {
            throw new \RuntimeException("Hold {$hold->reference} is not disputed.");
        }

        $hold->update(['status' => SettlementHold::STATUS_HELD]);
        $this->record($hold, 'dispute_resolved', $actor, ['note' => $outcome]);

        return match ($outcome) {
            'release' => $this->release($hold, $hold->remaining(), null, 'dispute resolved in seller favour'),
            'refund' => $this->refund($hold, $hold->remaining(), 'dispute resolved in buyer favour'),
            default => $hold->fresh(),
        };
    }

    // ─── Evaluation ───────────────────────────────────────────────────

    /**
     * Run the merchant's rules for one condition type and release if a rule
     * matches. The first matching rule by priority wins.
     */
    public function evaluate(SettlementHold $hold, string $conditionType): SettlementHold
    {
        if ($hold->isFrozen()) {
            $this->record($hold, 'release_blocked', 'system', ['note' => 'hold is disputed']);
            return $hold;
        }

        if (!$hold->isReleasable()) {
            return $hold;
        }

        $rule = SettlementRule::forMerchant($hold->merchant_id)
            ->active()
            ->where('condition_type', $conditionType)
            ->orderBy('priority')
            ->first();

        if (!$rule) {
            $this->record($hold, 'release_blocked', 'system', [
                'note' => "no active {$conditionType} rule for this merchant",
            ]);

            return $hold;
        }

        if (!$this->conditionMet($hold, $rule)) {
            return $hold;
        }

        $amount = $this->releaseAmount($hold, $rule);

        return $this->release($hold, $amount, $rule, $rule->name);
    }

    protected function conditionMet(SettlementHold $hold, SettlementRule $rule): bool
    {
        return match ($rule->condition_type) {
            // Both are triggered by something that already happened, so
            // reaching evaluate() is the condition.
            SettlementRule::CONDITION_BUYER_CONFIRMATION,
            SettlementRule::CONDITION_MANUAL_APPROVAL => true,

            SettlementRule::CONDITION_ELAPSED_DAYS =>
                $hold->held_at !== null
                && $hold->held_at->copy()->addDays($rule->days())->isPast(),

            default => false,
        };
    }

    /**
     * A percentage rule releases a share of the original amount, capped at
     * what is actually left: two 60% rules must not pay out 120%.
     */
    protected function releaseAmount(SettlementHold $hold, SettlementRule $rule): float
    {
        if ($rule->releasesInFull()) {
            return $hold->remaining();
        }

        $share = round((float) $hold->amount * ((int) $rule->release_percentage / 100), 2);

        return min($share, $hold->remaining());
    }

    // ─── Moving the money ─────────────────────────────────────────────

    /**
     * Pay the seller. This is the only place a payout is created, so every
     * release passes the same guards and leaves the same trail.
     */
    public function release(
        SettlementHold $hold,
        float $amount,
        ?SettlementRule $rule = null,
        string $reason = '',
    ): SettlementHold {
        if ($hold->isFrozen()) {
            throw new \RuntimeException("Cannot release a disputed hold ({$hold->reference}).");
        }

        $amount = round(min($amount, $hold->remaining()), 2);

        if ($amount <= 0) {
            return $hold;
        }

        $account = $hold->connectedAccount;

        if (!$account || !$account->canHoldFunds()) {
            $this->record($hold, 'release_failed', 'system', [
                'note' => 'connected account is not in a releasable state',
                'detail' => ['blockers' => $account?->holdBlockers() ?? ['account missing']],
            ]);

            throw new \RuntimeException("Connected account for {$hold->reference} cannot be paid out.");
        }

        try {
            $payout = $this->gateway()->createPayout(
                $account->account_id,
                $amount,
                $hold->currency,
                ['reference' => $hold->reference, 'reason' => $reason],
            );
        } catch (\Throwable $e) {
            $this->record($hold, 'release_failed', 'system', [
                'amount' => $amount,
                'note' => $e->getMessage(),
            ]);

            throw $e;
        }

        DB::transaction(function () use ($hold, $amount, $rule, $reason, $payout) {
            $released = round((float) $hold->amount_released + $amount, 2);
            $fullyOut = $released + (float) $hold->amount_refunded >= (float) $hold->amount;

            $hold->update([
                'amount_released' => $released,
                'status' => $fullyOut ? SettlementHold::STATUS_RELEASED : SettlementHold::STATUS_PART_RELEASED,
                'released_at' => $fullyOut ? now() : $hold->released_at,
                'released_by_rule_id' => $rule?->id ?? $hold->released_by_rule_id,
                'release_reason' => $reason ?: $hold->release_reason,
            ]);

            $this->record($hold, 'released', 'system', [
                'amount' => $amount,
                'rule_id' => $rule?->id,
                'note' => $reason,
                'detail' => ['payout_id' => $payout['payout_id'], 'payout_status' => $payout['status']],
            ]);
        });

        return $hold->fresh();
    }

    public function refund(SettlementHold $hold, float $amount, string $reason = ''): SettlementHold
    {
        $amount = round(min($amount, $hold->remaining()), 2);

        if ($amount <= 0) {
            return $hold;
        }

        $account = $hold->connectedAccount;

        $this->gateway()->processRefund(
            (string) $hold->payment_intent_id,
            $amount,
            $reason,
            $account?->account_id,
        );

        $refunded = round((float) $hold->amount_refunded + $amount, 2);
        $allOut = $refunded + (float) $hold->amount_released >= (float) $hold->amount;

        $hold->update([
            'amount_refunded' => $refunded,
            'status' => $allOut ? SettlementHold::STATUS_REFUNDED : $hold->status,
        ]);

        $this->record($hold, 'refunded', 'system', ['amount' => $amount, 'note' => $reason]);

        return $hold->fresh();
    }

    // ─── Scheduled sweep ──────────────────────────────────────────────

    /**
     * Run on a schedule. Fires elapsed-day rules, and force-releases anything
     * approaching Stripe's holding limit.
     *
     * @return array{released: int, forced: int, failed: int}
     */
    public function processDueHolds(): array
    {
        $summary = ['released' => 0, 'forced' => 0, 'failed' => 0];

        // Disputed holds are included deliberately. They cannot be released,
        // but the money is still sitting in the seller's balance and Stripe's
        // deadline still applies, so an unresolved dispute must be surfaced
        // rather than quietly overrunning the limit.
        SettlementHold::whereIn('status', [
            SettlementHold::STATUS_HELD,
            SettlementHold::STATUS_PART_RELEASED,
            SettlementHold::STATUS_DISPUTED,
        ])
            ->with('connectedAccount')
            ->orderBy('hold_expires_at')
            ->chunkById(100, function ($holds) use (&$summary) {
                foreach ($holds as $hold) {
                    try {
                        // Stripe's deadline wins over any rule. A disputed
                        // hold still cannot be paid out, so it is surfaced
                        // rather than released.
                        if ($hold->expiresWithin(self::EXPIRY_SAFETY_DAYS)) {
                            if ($hold->isFrozen()) {
                                $this->warnOnce($hold);
                                continue;
                            }

                            $this->release($hold, $hold->remaining(), null, 'Stripe holding limit reached');
                            $summary['forced']++;
                            continue;
                        }

                        // Nothing below applies to a frozen hold.
                        if ($hold->isFrozen()) {
                            continue;
                        }

                        $before = $hold->amount_released;
                        $after = $this->evaluate($hold, SettlementRule::CONDITION_ELAPSED_DAYS);

                        if ((float) $after->amount_released > (float) $before) {
                            $summary['released']++;
                        }
                    } catch (\Throwable $e) {
                        $summary['failed']++;
                        Log::error('Hold processing failed', [
                            'hold' => $hold->reference,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $summary;
    }

    // ─── Audit ────────────────────────────────────────────────────────

    /**
     * A disputed hold at Stripe's deadline needs a human. Warn once rather
     * than on every sweep, so the signal does not drown in repeats.
     */
    protected function warnOnce(SettlementHold $hold): void
    {
        $alreadyWarned = HoldEvent::where('settlement_hold_id', $hold->id)
            ->where('event_type', 'expiry_warning')
            ->exists();

        if ($alreadyWarned) {
            return;
        }

        $this->record($hold, 'expiry_warning', 'system', [
            'note' => 'disputed hold is reaching the Stripe holding limit and needs a decision',
            'detail' => ['hold_expires_at' => $hold->hold_expires_at?->toIso8601String()],
        ]);

        Log::warning('Disputed hold nearing the Stripe holding limit', [
            'hold' => $hold->reference,
            'expires_at' => $hold->hold_expires_at?->toDateString(),
        ]);
    }

    protected function record(SettlementHold $hold, string $type, string $actor, array $extra = []): HoldEvent
    {
        return HoldEvent::create([
            'settlement_hold_id' => $hold->id,
            'event_type' => $type,
            'actor' => $actor,
            'rule_id' => $extra['rule_id'] ?? null,
            'amount' => $extra['amount'] ?? null,
            'note' => $extra['note'] ?? null,
            'detail' => $extra['detail'] ?? null,
            'created_at' => now(),
        ]);
    }
}
