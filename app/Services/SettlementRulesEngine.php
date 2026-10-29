<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\HoldEvent;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Models\SettlementTransfer;
use App\PaymentGateways\StripeConnectGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decides when held money is released.
 *
 * Stripe holds the funds in the seller's balance and will pay them out when
 * told; nothing in Stripe decides when. That decision lives here.
 *
 * Four conditions release a hold:
 *   milestone           a named milestone is reached, optionally confirmed
 *                       by a named party (a carrier, an inspector, the payer)
 *   buyer_confirmation  the payer says they got what they paid for
 *   elapsed_days        a timer runs out with no dispute
 *   manual_approval     a human approves it
 *
 * Money is held in one of two places. An account-funded hold sits in one
 * holding account's own balance and a release pays that account out. A
 * pooled hold sits in the platform balance and a release is split across
 * the rule's destinations, each share transferred to its own account.
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
    protected SplitCalculator $splitter;

    public function __construct(EscrowPaymentService $paymentService, ?SplitCalculator $splitter = null)
    {
        $this->paymentService = $paymentService;
        $this->splitter = $splitter ?? new SplitCalculator();
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
            'funding' => SettlementHold::FUNDING_ACCOUNT,
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

    /**
     * Open a pooled hold and ask for the payment into the platform balance.
     *
     * The hold exists before the money does, so the payment can carry the
     * hold's transfer group from the start. It waits in awaiting_payment
     * until Stripe confirms the charge.
     *
     * @param array<string, string> $parties role => connected account id
     */
    public function openPooled(
        int $merchantId,
        string $reference,
        float $amount,
        string $currency,
        array $parties,
        ?string $flow,
        ?string $onExpiry,
        ?string $payerEmail,
        string $successUrl,
        string $cancelUrl,
    ): SettlementHold {
        $hold = SettlementHold::create([
            'merchant_id' => $merchantId,
            'funding' => SettlementHold::FUNDING_POOLED,
            'reference' => $reference,
            'flow' => $flow,
            'parties' => $parties,
            'on_expiry' => $onExpiry ?: 'refund',
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => SettlementHold::STATUS_AWAITING_PAYMENT,
        ]);

        $checkout = $this->gateway()->createPlatformCheckout(
            $amount,
            $currency,
            $reference,
            $this->transferGroup($hold),
            $payerEmail,
            $successUrl,
            $cancelUrl,
            ['peppa_hold_id' => (string) $hold->id, 'reference' => $reference],
        );

        $hold->update([
            'checkout_session_id' => $checkout['session_id'],
            'payment_url' => $checkout['payment_url'],
        ]);

        $this->record($hold, 'payment_requested', 'system', ['amount' => $amount]);

        return $hold->fresh();
    }

    /**
     * The payment for a pooled hold has arrived. Safe to call more than once:
     * Stripe retries webhooks, and a second delivery changes nothing.
     */
    public function paymentReceived(SettlementHold $hold, string $paymentIntentId): SettlementHold
    {
        if ($hold->status !== SettlementHold::STATUS_AWAITING_PAYMENT) {
            return $hold;
        }

        $gateway = $this->gateway();
        $payment = $gateway->platformPayment($paymentIntentId);

        if ($payment['status'] !== 'succeeded'
            || strtoupper($payment['currency']) !== $hold->currency
            || $payment['amount'] + 0.005 < (float) $hold->amount) {
            $this->record($hold, 'payment_rejected', 'system', [
                'amount' => $payment['amount'],
                'note' => sprintf('Expected %s %s, received %s %s (%s)',
                    $hold->amount, $hold->currency, $payment['amount'], $payment['currency'], $payment['status']),
            ]);

            return $hold;
        }

        $expiresAt = $gateway->holdExpiresAt($gateway->platformCountry());

        $hold->update([
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $payment['charge_id'],
            'status' => SettlementHold::STATUS_HELD,
            'held_at' => now(),
            'hold_expires_at' => $expiresAt,
        ]);

        $this->record($hold, 'held', 'system', [
            'amount' => (float) $hold->amount,
            'note' => sprintf('Payment received. Held until %s at the latest.', $expiresAt->toDateString()),
        ]);

        return $hold->fresh();
    }

    // ─── Conditions firing ────────────────────────────────────────────

    /**
     * A milestone has been reached. Whoever confirms it is recorded, and a
     * rule that names a confirming party only fires for that party.
     */
    public function milestoneReached(
        SettlementHold $hold,
        string $milestone,
        ?string $confirmedBy = null,
        ?string $actor = null,
    ): SettlementHold {
        if ($hold->status === SettlementHold::STATUS_AWAITING_PAYMENT) {
            throw new \RuntimeException("Payment for {$hold->reference} has not been received yet.");
        }

        $this->record($hold, 'milestone_reached', $actor ?? $confirmedBy ?? 'partner', [
            'note' => $milestone,
            'detail' => ['milestone' => $milestone, 'confirmed_by' => $confirmedBy],
        ]);

        return $this->evaluate($hold, SettlementRule::CONDITION_MILESTONE, [
            'milestone' => $milestone,
            'confirmed_by' => $confirmedBy,
        ]);
    }

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
     * @param array<int, array<string, mixed>>|null $destinations where a pooled
     *        hold's money goes on release; account-funded holds ignore it
     */
    public function resolveDispute(
        SettlementHold $hold,
        string $outcome,
        string $actor,
        ?array $destinations = null,
    ): SettlementHold {
        if (!$hold->isFrozen()) {
            throw new \RuntimeException("Hold {$hold->reference} is not disputed.");
        }

        // Checked before anything changes, so a refused resolution leaves the
        // hold disputed rather than half-resolved.
        if ($outcome === 'release' && $hold->isPooled() && empty($destinations)) {
            throw new \RuntimeException(
                'Releasing a pooled hold needs destinations saying where the money goes.'
            );
        }

        $hold->update(['status' => SettlementHold::STATUS_HELD]);
        $this->record($hold, 'dispute_resolved', $actor, ['note' => $outcome]);

        return match ($outcome) {
            'release' => $hold->isPooled()
                ? $this->releaseSplit($hold, $hold->remaining(), $destinations, null, 'dispute resolved: released')
                : $this->release($hold, $hold->remaining(), null, 'dispute resolved in seller favour'),
            'refund' => $this->refund($hold, $hold->remaining(), 'dispute resolved in buyer favour'),
            default => $hold->fresh(),
        };
    }

    // ─── Evaluation ───────────────────────────────────────────────────

    /**
     * Run the merchant's rules for one condition type and release if a rule
     * matches. The first matching rule by priority wins.
     *
     * @param array{milestone?: string, confirmed_by?: ?string} $context
     */
    public function evaluate(SettlementHold $hold, string $conditionType, array $context = []): SettlementHold
    {
        if ($hold->isFrozen()) {
            $this->record($hold, 'release_blocked', 'system', ['note' => 'hold is disputed']);
            return $hold;
        }

        if (!$hold->isReleasable()) {
            return $hold;
        }

        [$rule, $why] = $this->matchingRule($hold, $conditionType, $context);

        if (!$rule) {
            $this->record($hold, 'release_blocked', 'system', [
                'note' => $why ?? "no active {$conditionType} rule for this merchant",
            ]);

            return $hold;
        }

        if (!$this->conditionMet($hold, $rule)) {
            return $hold;
        }

        $amount = $this->releaseAmount($hold, $rule);

        return $hold->isPooled()
            ? $this->releaseSplit($hold, $amount, $rule->destinations, $rule, $rule->name)
            : $this->release($hold, $amount, $rule, $rule->name);
    }

    /**
     * The first rule, by priority, that applies to this hold.
     *
     * A rule applies when its flow matches the hold's (or it has none), when
     * it suits where the money is (split rules for pooled holds, plain rules
     * for account-funded ones), and for milestones when it names this
     * milestone and either names no confirming party or names this one. A
     * milestone rule fires once per hold: confirming the same milestone
     * twice must not pay out twice.
     *
     * @return array{0: ?SettlementRule, 1: ?string} the rule, or why none applied
     */
    protected function matchingRule(SettlementHold $hold, string $conditionType, array $context): array
    {
        $candidates = SettlementRule::forMerchant($hold->merchant_id)
            ->active()
            ->where('condition_type', $conditionType)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $why = null;

        foreach ($candidates as $rule) {
            if ($rule->flow !== null && $rule->flow !== $hold->flow) {
                continue;
            }

            if ($rule->splitsRelease() !== $hold->isPooled()) {
                continue;
            }

            if ($conditionType === SettlementRule::CONDITION_MILESTONE) {
                if ($rule->milestone() !== ($context['milestone'] ?? null)) {
                    continue;
                }

                if ($rule->confirmedBy() !== null && $rule->confirmedBy() !== ($context['confirmed_by'] ?? null)) {
                    $why = sprintf('milestone "%s" only counts when confirmed by %s',
                        $rule->milestone(), $rule->confirmedBy());
                    continue;
                }

                if ($this->ruleAlreadyReleased($hold, $rule)) {
                    $why = sprintf('rule "%s" has already released this payment', $rule->name);
                    continue;
                }
            }

            return [$rule, null];
        }

        if ($why === null && $conditionType === SettlementRule::CONDITION_MILESTONE) {
            $why = sprintf('no active rule for milestone "%s"', $context['milestone'] ?? '');
        }

        return [null, $why];
    }

    protected function ruleAlreadyReleased(SettlementHold $hold, SettlementRule $rule): bool
    {
        return HoldEvent::where('settlement_hold_id', $hold->id)
            ->where('event_type', 'released')
            ->where('rule_id', $rule->id)
            ->exists();
    }

    protected function conditionMet(SettlementHold $hold, SettlementRule $rule): bool
    {
        return match ($rule->condition_type) {
            // Both are triggered by something that already happened, so
            // reaching evaluate() is the condition.
            SettlementRule::CONDITION_BUYER_CONFIRMATION,
            SettlementRule::CONDITION_MANUAL_APPROVAL,
            SettlementRule::CONDITION_MILESTONE => true,

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

        if ($hold->isPooled()) {
            throw new \RuntimeException("A pooled hold ({$hold->reference}) is released through its rule's destinations.");
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

    /**
     * Release part of a pooled hold, split across destinations.
     *
     * Every destination is resolved to a ready account before anything is
     * committed, so a missing or half-onboarded party stops the whole
     * release rather than paying some shares and stranding the rest. The
     * shares are then written down and the hold's balance reduced in one
     * transaction, and only after that is money moved. A transfer that fails
     * is left marked failed and retried; it is never recalculated, so a retry
     * can only ever send what was originally decided.
     *
     * @param array<int, array<string, mixed>> $destinations
     */
    public function releaseSplit(
        SettlementHold $hold,
        float $amount,
        array $destinations,
        ?SettlementRule $rule = null,
        string $reason = '',
    ): SettlementHold {
        if ($hold->isFrozen()) {
            throw new \RuntimeException("Cannot release a disputed hold ({$hold->reference}).");
        }

        if (!$hold->isPooled()) {
            throw new \RuntimeException("Only a pooled hold can be split ({$hold->reference}).");
        }

        $amount = round(min($amount, $hold->remaining()), 2);

        if ($amount <= 0) {
            return $hold;
        }

        if ($error = SplitCalculator::validate($destinations)) {
            throw new \InvalidArgumentException($error);
        }

        try {
            $shares = $this->splitter->split($amount, $hold->currency, $destinations);
        } catch (\InvalidArgumentException $e) {
            $this->record($hold, 'release_failed', 'system', [
                'amount' => $amount, 'rule_id' => $rule?->id, 'note' => $e->getMessage(),
            ]);

            throw $e;
        }

        $problems = [];

        foreach ($shares as $i => $share) {
            if ($share['to'] === SettlementRule::PLATFORM_DESTINATION) {
                $shares[$i]['account'] = null;
                continue;
            }

            $accountId = str_starts_with($share['to'], 'acct_') ? $share['to'] : $hold->party($share['to']);

            if (!$accountId) {
                $problems[] = sprintf('No account is assigned to "%s" on this payment.', $share['to']);
                continue;
            }

            $account = ConnectedAccount::byMerchant($hold->merchant_id)->where('account_id', $accountId)->first();

            if (!$account) {
                $problems[] = sprintf('%s (%s) is not one of your accounts.', $accountId, $share['to']);
                continue;
            }

            if (!$account->canReceiveTransfers()) {
                $problems[] = sprintf('%s (%s) cannot receive money yet: %s',
                    $share['to'], $accountId, implode(' ', $account->transferBlockers()));
                continue;
            }

            $shares[$i]['account'] = $account;
        }

        if ($problems) {
            $this->record($hold, 'release_failed', 'system', [
                'amount' => $amount,
                'rule_id' => $rule?->id,
                'note' => 'Nothing was released. ' . implode(' ', $problems),
                'detail' => ['problems' => $problems],
            ]);

            throw new \RuntimeException('Nothing was released. ' . implode(' ', $problems));
        }

        $releaseId = (string) Str::uuid();

        $lines = DB::transaction(function () use ($hold, $amount, $rule, $reason, $shares, $releaseId) {
            $lines = [];

            foreach ($shares as $n => $share) {
                $account = $share['account'];

                $lines[] = SettlementTransfer::create([
                    'settlement_hold_id' => $hold->id,
                    'rule_id' => $rule?->id,
                    'release_id' => $releaseId,
                    'destination' => $share['to'],
                    'connected_account_id' => $account?->id,
                    'account_id' => $account?->account_id,
                    'amount' => $share['amount'],
                    'currency' => $hold->currency,
                    'status' => $account ? SettlementTransfer::STATUS_PENDING : SettlementTransfer::STATUS_RETAINED,
                    'idempotency_key' => "hold-{$hold->id}-{$releaseId}-{$n}",
                ]);
            }

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
                'detail' => [
                    'release_id' => $releaseId,
                    'shares' => array_map(fn ($s) => ['to' => $s['to'], 'amount' => $s['amount']], $shares),
                ],
            ]);

            return $lines;
        });

        $this->sendTransfers($hold, $lines);

        return $hold->fresh();
    }

    /**
     * Send pending or failed shares. Each carries the idempotency key it was
     * created with, so resending after a timeout cannot pay twice.
     *
     * @param iterable<SettlementTransfer> $lines
     * @return int how many failed
     */
    public function sendTransfers(SettlementHold $hold, iterable $lines): int
    {
        $failed = 0;

        foreach ($lines as $line) {
            if (!in_array($line->status, [SettlementTransfer::STATUS_PENDING, SettlementTransfer::STATUS_FAILED], true)) {
                continue;
            }

            try {
                $result = $this->gateway()->createTransfer(
                    (string) $line->account_id,
                    (float) $line->amount,
                    $line->currency,
                    $this->transferGroup($hold),
                    $hold->charge_id,
                    $line->idempotency_key,
                    [
                        'reference' => $hold->reference,
                        'destination' => $line->destination,
                        'release_id' => $line->release_id,
                    ],
                );

                $line->update([
                    'status' => SettlementTransfer::STATUS_PAID,
                    'transfer_id' => $result['transfer_id'],
                    'error' => null,
                ]);
            } catch (\Throwable $e) {
                $failed++;

                $line->update(['status' => SettlementTransfer::STATUS_FAILED, 'error' => $e->getMessage()]);

                $this->record($hold, 'transfer_failed', 'system', [
                    'amount' => (float) $line->amount,
                    'note' => sprintf('%s: %s', $line->destination, $e->getMessage()),
                    'detail' => ['transfer_line' => $line->id],
                ]);

                Log::error('Split release transfer failed', [
                    'hold' => $hold->reference,
                    'destination' => $line->destination,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failed;
    }

    /**
     * Resend every share that failed. The sweep calls this so a transient
     * Stripe error resolves itself; partners can call it for one hold.
     *
     * @return array{retried: int, failed: int}
     */
    public function retryFailedTransfers(?SettlementHold $only = null): array
    {
        $summary = ['retried' => 0, 'failed' => 0];

        $query = SettlementTransfer::where('status', SettlementTransfer::STATUS_FAILED)->with('hold');

        if ($only) {
            $query->where('settlement_hold_id', $only->id);
        }

        $query->get()->groupBy('settlement_hold_id')->each(function ($lines) use (&$summary) {
            $summary['retried'] += $lines->count();
            $summary['failed'] += $this->sendTransfers($lines->first()->hold, $lines);
        });

        return $summary;
    }

    /** Ties a pooled hold's payment and every share of its release together in Stripe. */
    protected function transferGroup(SettlementHold $hold): string
    {
        return 'hold_' . $hold->id;
    }

    public function refund(SettlementHold $hold, float $amount, string $reason = ''): SettlementHold
    {
        if ($hold->status === SettlementHold::STATUS_AWAITING_PAYMENT) {
            throw new \RuntimeException("Payment for {$hold->reference} has not been received, so there is nothing to refund.");
        }

        $amount = round(min($amount, $hold->remaining()), 2);

        if ($amount <= 0) {
            return $hold;
        }

        if ($hold->isPooled()) {
            $this->gateway()->refundPlatformPayment(
                (string) $hold->payment_intent_id,
                $amount,
                $hold->currency,
                $reason,
            );
        } else {
            $this->gateway()->processRefund(
                (string) $hold->payment_intent_id,
                $amount,
                $reason,
                $hold->connectedAccount?->account_id,
            );
        }

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

                            $hold->isPooled()
                                ? $this->expirePooled($hold)
                                : $this->release($hold, $hold->remaining(), null, 'Stripe holding limit reached');
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

        $retries = $this->retryFailedTransfers();
        $summary['failed'] += $retries['failed'];

        return $summary;
    }

    /**
     * A pooled hold at the holding limit goes where the partner said it
     * should when the hold was opened: back to the payer by default, or in
     * full to a named party.
     */
    protected function expirePooled(SettlementHold $hold): SettlementHold
    {
        $reason = 'holding limit reached';

        if (($hold->on_expiry ?? 'refund') === 'refund') {
            return $this->refund($hold, $hold->remaining(), $reason);
        }

        return $this->releaseSplit($hold, $hold->remaining(), [
            ['to' => $hold->on_expiry, 'remainder' => true],
        ], null, $reason);
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
