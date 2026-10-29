<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\HoldEvent;
use App\Models\SettlementHold;
use App\Models\SettlementRule;
use App\Models\SettlementShare;
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
 * Peppa never holds the money. An account-funded hold sits in one holding
 * account's own balance and a release pays that account out. A split hold
 * is divided the moment it is paid: each share is transferred into its
 * participant's own locked account, and a release pays each participant
 * out from there.
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
     * Work out a split payment's shares and the account each one goes to.
     *
     * Used when the hold is opened, so a missing or unready participant is
     * refused before the payer is asked for anything.
     *
     * @param array<int, array<string, mixed>> $split
     * @param array<string, string> $parties role => connected account id
     * @return array<int, array{to: string, amount: float, account: ?ConnectedAccount}>
     *
     * @throws \InvalidArgumentException when the split itself is unsound
     * @throws \RuntimeException listing every participant that cannot take a share
     */
    public function planShares(int $merchantId, float $amount, string $currency, array $split, array $parties): array
    {
        if ($error = SplitCalculator::validate($split)) {
            throw new \InvalidArgumentException($error);
        }

        $shares = $this->splitter->split($amount, $currency, $split);
        $problems = [];

        foreach ($shares as $i => $share) {
            $shares[$i]['account'] = null;

            if ($share['to'] === SettlementRule::PLATFORM_DESTINATION) {
                continue;
            }

            $accountId = str_starts_with($share['to'], 'acct_') ? $share['to'] : ($parties[$share['to']] ?? null);

            if (!$accountId) {
                $problems[] = sprintf('No account is assigned to "%s".', $share['to']);
                continue;
            }

            $account = ConnectedAccount::byMerchant($merchantId)->where('account_id', $accountId)->first();

            if (!$account) {
                $problems[] = sprintf('%s (%s) is not one of your accounts.', $accountId, $share['to']);
                continue;
            }

            if (!$account->canHoldShares()) {
                $problems[] = sprintf('%s (%s) cannot hold a share yet: %s',
                    $share['to'], $accountId, implode(' ', $account->shareBlockers()));
                continue;
            }

            $shares[$i]['account'] = $account;
        }

        if ($problems) {
            throw new \RuntimeException(implode(' ', $problems));
        }

        return $shares;
    }

    /**
     * Open a split hold and ask the payer for the money.
     *
     * The shares are fixed now, because each one is transferred into its
     * participant's account the moment the payment succeeds. The hold waits
     * in awaiting_payment until Stripe confirms the charge.
     *
     * @param array<int, array<string, mixed>> $split
     * @param array<string, string> $parties role => connected account id
     */
    public function openSplit(
        int $merchantId,
        string $reference,
        float $amount,
        string $currency,
        array $split,
        array $parties,
        ?string $flow,
        ?string $onExpiry,
        ?string $payerEmail,
        string $successUrl,
        string $cancelUrl,
    ): SettlementHold {
        $currency = strtoupper($currency);
        $planned = $this->planShares($merchantId, $amount, $currency, $split, $parties);

        $hold = DB::transaction(function () use ($merchantId, $reference, $amount, $currency, $split, $parties, $flow, $onExpiry, $planned) {
            $hold = SettlementHold::create([
                'merchant_id' => $merchantId,
                'funding' => SettlementHold::FUNDING_SPLIT,
                'reference' => $reference,
                'flow' => $flow,
                'parties' => $parties,
                'split' => $split,
                'on_expiry' => $onExpiry ?: 'refund',
                'amount' => $amount,
                'currency' => $currency,
                'status' => SettlementHold::STATUS_AWAITING_PAYMENT,
            ]);

            foreach ($planned as $share) {
                SettlementShare::create([
                    'settlement_hold_id' => $hold->id,
                    'destination' => $share['to'],
                    'connected_account_id' => $share['account']?->id,
                    'account_id' => $share['account']?->account_id,
                    'amount' => $share['amount'],
                    'currency' => $currency,
                ]);
            }

            return $hold;
        });

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
     * The payment for a split hold has arrived: share it out at once. Safe to
     * call more than once, since Stripe retries webhooks.
     *
     * The deadline is the earliest of the participants' holding limits, since
     * each share is held in its own participant's account.
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

        $shares = $hold->shares()->with('connectedAccount')->get();
        $countries = $shares->pluck('connectedAccount.country')->filter()->unique();

        $expiresAt = $countries->isEmpty()
            ? $gateway->holdExpiresAt($gateway->platformCountry())
            : $countries->map(fn ($c) => $gateway->holdExpiresAt($c))->sort()->first();

        $lines = DB::transaction(function () use ($hold, $paymentIntentId, $payment, $expiresAt, $shares) {
            $hold->update([
                'payment_intent_id' => $paymentIntentId,
                'charge_id' => $payment['charge_id'],
                'status' => SettlementHold::STATUS_HELD,
                'held_at' => now(),
                'hold_expires_at' => $expiresAt,
            ]);

            $this->record($hold, 'held', 'system', [
                'amount' => (float) $hold->amount,
                'note' => sprintf('Payment received and shared into the participants\' accounts. Release by %s at the latest.',
                    $expiresAt->toDateString()),
            ]);

            return $shares->reject->isPlatform()->map(fn (SettlementShare $share) => SettlementTransfer::create([
                'settlement_hold_id' => $hold->id,
                'settlement_share_id' => $share->id,
                'kind' => SettlementTransfer::KIND_TRANSFER,
                'destination' => $share->destination,
                'connected_account_id' => $share->connected_account_id,
                'account_id' => $share->account_id,
                'amount' => $share->amount,
                'currency' => $share->currency,
                'status' => SettlementTransfer::STATUS_PENDING,
                'idempotency_key' => "share-{$share->id}-transfer",
            ]))->values();
        });

        $this->sendMovements($hold, $lines);

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
     */
    public function resolveDispute(SettlementHold $hold, string $outcome, string $actor): SettlementHold
    {
        if (!$hold->isFrozen()) {
            throw new \RuntimeException("Hold {$hold->reference} is not disputed.");
        }

        $hold->update(['status' => SettlementHold::STATUS_HELD]);
        $this->record($hold, 'dispute_resolved', $actor, ['note' => $outcome]);

        return match ($outcome) {
            'release' => $hold->isSplit()
                ? $this->releaseShares($hold, 100, null, null, 'dispute resolved: released')
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

        return $hold->isSplit()
            ? $this->releaseShares($hold, (int) $rule->release_percentage, $rule->parties, $rule, $rule->name)
            : $this->release($hold, $this->releaseAmount($hold, $rule), $rule, $rule->name);
    }

    /**
     * The first rule, by priority, that applies to this hold.
     *
     * A rule applies when its flow matches the hold's (or it has none), when
     * it names parties only if the hold is split, and for milestones when it
     * names this milestone and either names no confirming party or names this
     * one. A milestone rule fires once per hold: confirming the same
     * milestone twice must not pay out twice.
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

            if ($rule->namesParties() && !$hold->isSplit()) {
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
            // Triggered by something that already happened, so reaching
            // evaluate() is the condition.
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

    // ─── Moving the money: account-funded holds ───────────────────────

    /**
     * Pay the seller. This is the only place an account-funded hold is paid
     * out, so every release passes the same guards and leaves the same trail.
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

        if ($hold->isSplit()) {
            throw new \RuntimeException("A split hold ({$hold->reference}) is released share by share.");
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

    // ─── Moving the money: split holds ────────────────────────────────

    /**
     * Release shares of a split hold: each participant is paid out from the
     * share already sitting in their own account.
     *
     * `$percentage` is of each share's original amount, capped at what is
     * left of it, so staged releases add up exactly as they do on a single
     * account. `$parties` limits the release to those shares (by role or
     * account id); null releases every share.
     *
     * Every participant is checked before anything is committed, so one
     * participant who cannot be paid out stops the release rather than
     * paying some and stranding the rest. The release is then recorded in
     * one transaction and only after that are payouts sent; a payout that
     * fails is retried with the same key and is never recalculated.
     *
     * @param array<int, string>|null $parties
     */
    public function releaseShares(
        SettlementHold $hold,
        int $percentage,
        ?array $parties = null,
        ?SettlementRule $rule = null,
        string $reason = '',
    ): SettlementHold {
        if ($hold->isFrozen()) {
            throw new \RuntimeException("Cannot release a disputed hold ({$hold->reference}).");
        }

        if (!$hold->isSplit()) {
            throw new \RuntimeException("Only a split hold is released share by share ({$hold->reference}).");
        }

        $shares = $hold->shares()->with('connectedAccount')->get()
            ->filter(fn (SettlementShare $s) => $parties === null
                || in_array($s->destination, $parties, true)
                || in_array($s->account_id, $parties, true))
            ->filter(fn (SettlementShare $s) => $s->remaining() > 0)
            ->values();

        if ($shares->isEmpty()) {
            $this->record($hold, 'release_blocked', 'system', [
                'rule_id' => $rule?->id,
                'note' => $parties === null
                    ? 'nothing left to release'
                    : 'nothing left to release for ' . implode(', ', $parties),
            ]);

            return $hold;
        }

        $problems = [];

        foreach ($shares as $share) {
            $account = $share->connectedAccount;

            if (!$share->isPlatform() && (!$account || !$account->payouts_enabled)) {
                $problems[] = sprintf('%s (%s) cannot be paid out: payouts are not enabled on the account.',
                    $share->destination, $share->account_id);
            }
        }

        if ($problems) {
            $this->record($hold, 'release_failed', 'system', [
                'rule_id' => $rule?->id,
                'note' => 'Nothing was released. ' . implode(' ', $problems),
                'detail' => ['problems' => $problems],
            ]);

            throw new \RuntimeException('Nothing was released. ' . implode(' ', $problems));
        }

        $releaseId = (string) Str::uuid();

        $lines = DB::transaction(function () use ($hold, $shares, $percentage, $rule, $reason, $releaseId) {
            $lines = [];
            $total = 0.0;
            $detail = [];

            foreach ($shares as $share) {
                $amount = $percentage >= 100
                    ? $share->remaining()
                    : min(round((float) $share->amount * $percentage / 100, 2), $share->remaining());

                if ($amount <= 0) {
                    continue;
                }

                $share->update(['amount_released' => round((float) $share->amount_released + $amount, 2)]);
                $total += $amount;
                $detail[] = ['to' => $share->destination, 'amount' => $amount];

                // Peppa's own fee is never transferred, so there is nothing
                // to pay out; releasing it is bookkeeping.
                if ($share->isPlatform()) {
                    $share->update(['amount_paid_out' => $share->amount_released]);
                    continue;
                }

                $lines[] = SettlementTransfer::create([
                    'settlement_hold_id' => $hold->id,
                    'settlement_share_id' => $share->id,
                    'kind' => SettlementTransfer::KIND_PAYOUT,
                    'rule_id' => $rule?->id,
                    'release_id' => $releaseId,
                    'destination' => $share->destination,
                    'connected_account_id' => $share->connected_account_id,
                    'account_id' => $share->account_id,
                    'amount' => $amount,
                    'currency' => $share->currency,
                    'status' => SettlementTransfer::STATUS_PENDING,
                    'idempotency_key' => "share-{$share->id}-payout-{$releaseId}",
                ]);
            }

            $total = round($total, 2);
            $released = round((float) $hold->amount_released + $total, 2);
            $fullyOut = $released + (float) $hold->amount_refunded >= (float) $hold->amount;

            $hold->update([
                'amount_released' => $released,
                'status' => $fullyOut ? SettlementHold::STATUS_RELEASED : SettlementHold::STATUS_PART_RELEASED,
                'released_at' => $fullyOut ? now() : $hold->released_at,
                'released_by_rule_id' => $rule?->id ?? $hold->released_by_rule_id,
                'release_reason' => $reason ?: $hold->release_reason,
            ]);

            $this->record($hold, 'released', 'system', [
                'amount' => $total,
                'rule_id' => $rule?->id,
                'note' => $reason,
                'detail' => ['release_id' => $releaseId, 'shares' => $detail],
            ]);

            return $lines;
        });

        $this->sendMovements($hold, $lines);

        return $hold->fresh();
    }

    /**
     * Carry out pending or failed share movements: transfers into the
     * participants' accounts, then payouts out of them. Each keeps the
     * idempotency key it was created with, so resending after a timeout
     * cannot move money twice.
     *
     * @param iterable<SettlementTransfer> $lines
     * @return int how many failed
     */
    public function sendMovements(SettlementHold $hold, iterable $lines): int
    {
        $failed = 0;

        foreach ($lines as $line) {
            if (!in_array($line->status, [SettlementTransfer::STATUS_PENDING, SettlementTransfer::STATUS_FAILED], true)) {
                continue;
            }

            try {
                $providerId = match ($line->kind) {
                    SettlementTransfer::KIND_TRANSFER => $this->gateway()->createTransfer(
                        (string) $line->account_id,
                        (float) $line->amount,
                        $line->currency,
                        $this->transferGroup($hold),
                        $hold->charge_id,
                        $line->idempotency_key,
                        ['reference' => $hold->reference, 'share' => $line->destination],
                    )['transfer_id'],

                    SettlementTransfer::KIND_PAYOUT => $this->payOut($line),

                    default => throw new \LogicException("Movement {$line->id} of kind {$line->kind} is not retried."),
                };

                DB::transaction(function () use ($line, $providerId) {
                    $line->update([
                        'status' => SettlementTransfer::STATUS_PAID,
                        'provider_id' => $providerId,
                        'error' => null,
                    ]);

                    if ($line->kind === SettlementTransfer::KIND_PAYOUT) {
                        $share = $line->share;
                        $share->update(['amount_paid_out' => round((float) $share->amount_paid_out + (float) $line->amount, 2)]);
                    }
                });
            } catch (\Throwable $e) {
                $failed++;

                $line->update(['status' => SettlementTransfer::STATUS_FAILED, 'error' => $e->getMessage()]);

                $this->record($hold, $line->kind . '_failed', 'system', [
                    'amount' => (float) $line->amount,
                    'note' => sprintf('%s: %s', $line->destination, $e->getMessage()),
                    'detail' => ['movement' => $line->id],
                ]);

                Log::error('Split share movement failed', [
                    'hold' => $hold->reference,
                    'kind' => $line->kind,
                    'destination' => $line->destination,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failed;
    }

    /**
     * A share can only be paid out once it has actually reached the
     * participant's account.
     */
    protected function payOut(SettlementTransfer $line): string
    {
        $share = $line->share()->with('movements')->first();

        if (!$share || !$share->allocation()) {
            throw new \RuntimeException('The share has not reached the participant\'s account yet.');
        }

        return $this->gateway()->createPayout(
            (string) $line->account_id,
            (float) $line->amount,
            $line->currency,
            ['reference' => $line->hold->reference, 'share' => $line->destination],
            $line->idempotency_key,
        )['payout_id'];
    }

    /**
     * Resend every share movement that failed, transfers before payouts.
     * The sweep calls this so a transient Stripe error resolves itself;
     * partners can call it for one hold.
     *
     * @return array{retried: int, failed: int}
     */
    public function retryFailedMovements(?SettlementHold $only = null): array
    {
        $summary = ['retried' => 0, 'failed' => 0];

        $query = SettlementTransfer::where('status', SettlementTransfer::STATUS_FAILED)
            ->whereIn('kind', [SettlementTransfer::KIND_TRANSFER, SettlementTransfer::KIND_PAYOUT])
            ->with('hold')
            ->orderByRaw("case when kind = 'transfer' then 0 else 1 end")
            ->orderBy('id');

        if ($only) {
            $query->where('settlement_hold_id', $only->id);
        }

        $query->get()->groupBy('settlement_hold_id')->each(function ($lines) use (&$summary) {
            $summary['retried'] += $lines->count();
            $summary['failed'] += $this->sendMovements($lines->first()->hold, $lines);
        });

        return $summary;
    }

    /** Ties a split payment and every share of it together in Stripe. */
    protected function transferGroup(SettlementHold $hold): string
    {
        return 'hold_' . $hold->id;
    }

    // ─── Refunds ──────────────────────────────────────────────────────

    public function refund(SettlementHold $hold, float $amount, string $reason = ''): SettlementHold
    {
        if ($hold->status === SettlementHold::STATUS_AWAITING_PAYMENT) {
            throw new \RuntimeException("Payment for {$hold->reference} has not been received, so there is nothing to refund.");
        }

        $amount = round(min($amount, $hold->remaining()), 2);

        if ($amount <= 0) {
            return $hold;
        }

        if ($hold->isSplit()) {
            return $this->refundSplit($hold, $amount, $reason);
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

    /**
     * Refund a split payment. The refund is taken from every share that is
     * still held, in proportion to what each has left, and each
     * participant's portion is pulled back from their account before the
     * payer is refunded. If a pull-back fails, only what was recovered is
     * refunded and the hold says so; nothing is refunded out of Peppa's own
     * money.
     */
    protected function refundSplit(SettlementHold $hold, float $amount, string $reason): SettlementHold
    {
        $shares = $hold->shares()->with('movements')->get()->filter(fn ($s) => $s->remaining() > 0)->values();

        foreach ($shares as $share) {
            if (!$share->isPlatform() && !$share->allocation()) {
                throw new \RuntimeException(
                    "The payment for {$hold->reference} is still being shared out. Try the refund again shortly."
                );
            }
        }

        $portions = $this->proportion($amount, $hold->currency, $shares->map->remaining()->all());
        $refundId = (string) Str::uuid();
        $recovered = 0.0;
        $failures = [];

        foreach ($shares as $i => $share) {
            $portion = $portions[$i];

            if ($portion <= 0) {
                continue;
            }

            if (!$share->isPlatform()) {
                $line = SettlementTransfer::create([
                    'settlement_hold_id' => $hold->id,
                    'settlement_share_id' => $share->id,
                    'kind' => SettlementTransfer::KIND_REVERSAL,
                    'release_id' => $refundId,
                    'destination' => $share->destination,
                    'connected_account_id' => $share->connected_account_id,
                    'account_id' => $share->account_id,
                    'amount' => $portion,
                    'currency' => $share->currency,
                    'status' => SettlementTransfer::STATUS_PENDING,
                    'idempotency_key' => "share-{$share->id}-reversal-{$refundId}",
                ]);

                try {
                    $result = $this->gateway()->reverseTransfer(
                        (string) $share->allocation()->provider_id,
                        $portion,
                        $share->currency,
                        $line->idempotency_key,
                    );
                    $line->update(['status' => SettlementTransfer::STATUS_PAID, 'provider_id' => $result['reversal_id']]);
                } catch (\Throwable $e) {
                    $line->update(['status' => SettlementTransfer::STATUS_FAILED, 'error' => $e->getMessage()]);
                    $failures[] = sprintf('%s: %s', $share->destination, $e->getMessage());
                    continue;
                }
            }

            $share->update(['amount_refunded' => round((float) $share->amount_refunded + $portion, 2)]);
            $recovered += $portion;
        }

        $recovered = round($recovered, 2);

        if ($recovered > 0) {
            $this->gateway()->refundPlatformPayment(
                (string) $hold->payment_intent_id,
                $recovered,
                $hold->currency,
                $reason,
            );

            $refunded = round((float) $hold->amount_refunded + $recovered, 2);
            $allOut = $refunded + (float) $hold->amount_released >= (float) $hold->amount;

            $hold->update([
                'amount_refunded' => $refunded,
                'status' => $allOut ? SettlementHold::STATUS_REFUNDED : $hold->status,
            ]);

            $this->record($hold, 'refunded', 'system', ['amount' => $recovered, 'note' => $reason]);
        }

        if ($failures) {
            $this->record($hold, 'refund_incomplete', 'system', [
                'amount' => round($amount - $recovered, 2),
                'note' => 'Could not recover from ' . implode('; ', $failures),
            ]);

            throw new \RuntimeException(sprintf(
                'Refunded %s of %s %s. Could not recover the rest from %s',
                number_format($recovered, 2, '.', ''), number_format($amount, 2, '.', ''), $hold->currency,
                implode('; ', $failures),
            ));
        }

        return $hold->fresh();
    }

    /**
     * Divide an amount across weights, in minor units, so the parts add up to
     * the amount exactly.
     *
     * @param array<int, float> $weights
     * @return array<int, float>
     */
    protected function proportion(float $amount, string $currency, array $weights): array
    {
        $scale = in_array(strtoupper($currency), StripeConnectGateway::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
        $total = (int) round($amount * $scale);
        $weightSum = array_sum($weights);

        if ($weightSum <= 0) {
            return array_fill(0, count($weights), 0.0);
        }

        $parts = [];
        $given = 0;
        foreach ($weights as $i => $w) {
            $parts[$i] = (int) floor($total * $w / $weightSum);
            $given += $parts[$i];
        }

        // Rounding leftovers go one unit at a time to the largest weights.
        arsort($weights);
        foreach (array_keys($weights) as $i) {
            if ($given >= $total) {
                break;
            }
            $parts[$i]++;
            $given++;
        }

        ksort($parts);

        return array_map(fn ($p) => $p / $scale, $parts);
    }

    // ─── Scheduled sweep ──────────────────────────────────────────────

    /**
     * Run on a schedule. Fires elapsed-day rules, force-releases anything
     * approaching Stripe's holding limit, and retries failed share movements.
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

                            $hold->isSplit()
                                ? $this->expireSplit($hold)
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

        $retries = $this->retryFailedMovements();
        $summary['failed'] += $retries['failed'];

        return $summary;
    }

    /**
     * A split hold at the holding limit is settled as the partner said when
     * it was opened: refunded to the payer by default, or released to every
     * participant.
     */
    protected function expireSplit(SettlementHold $hold): SettlementHold
    {
        $reason = 'holding limit reached';

        if (($hold->on_expiry ?? 'refund') === 'release') {
            return $this->releaseShares($hold, 100, null, null, $reason);
        }

        return $this->refund($hold, $hold->remaining(), $reason);
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
