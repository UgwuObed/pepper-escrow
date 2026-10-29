<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\SettlementHold;
use App\Services\SettlementRulesEngine;
use App\Services\SplitCalculator;
use App\Services\TenantService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The partner-facing API for held payments.
 *
 * Peppa is infrastructure: the partner's own system calls these on behalf of
 * its buyers and sellers, authenticated by the partner's API key. The actor
 * is therefore something the caller asserts, and it is recorded on the audit
 * trail rather than trusted for authorisation.
 *
 * Holds are addressed by the partner's own reference, not our row id, so a
 * partner never has to store an id of ours to act on a payment.
 */
class SettlementHoldController extends Controller
{
    protected TenantService $tenantService;
    protected SettlementRulesEngine $engine;

    public function __construct(TenantService $tenantService, SettlementRulesEngine $engine)
    {
        $this->tenantService = $tenantService;
        $this->engine = $engine;
    }

    /**
     * Open a hold.
     *
     * With funding "account" (the default) the payment has already landed in
     * a holding account's balance and this records it. With funding "pooled"
     * Peppa collects the payment into the platform balance and returns a
     * payment_url to send the payer to; the hold starts as awaiting_payment.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->input('funding') === SettlementHold::FUNDING_POOLED) {
            return $this->storePooled($request);
        }

        $validator = Validator::make($request->all(), [
            'reference' => 'required|string|max:191',
            'connected_account_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'payment_intent_id' => 'nullable|string',
            'transaction_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        try {
            $merchant = $this->merchant($request);

            $account = ConnectedAccount::byMerchant($merchant->id)
                ->where('account_id', $request->input('connected_account_id'))
                ->first();

            if (!$account) {
                return $this->fail('Connected account not found for this merchant.', 404);
            }

            // A seller who cannot be held is a seller whose money we cannot
            // control. Refusing here is better than discovering it at release.
            if (!$account->canHoldFunds()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This seller account cannot hold funds yet.',
                    'blockers' => $account->holdBlockers(),
                ], 409);
            }

            $reference = $request->input('reference');

            // Idempotent: the same reference twice returns the existing hold
            // rather than opening a second one against the same payment.
            $existing = SettlementHold::forMerchant($merchant->id)->where('reference', $reference)->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'data' => $this->present($existing),
                    'message' => 'Hold already exists for this reference.',
                ], 200);
            }

            $hold = $this->engine->hold(
                $account,
                $reference,
                (float) $request->input('amount'),
                strtoupper($request->input('currency', 'GBP')),
                $request->input('payment_intent_id'),
                $request->input('transaction_id'),
            );

            return response()->json(['status' => 'success', 'data' => $this->present($hold)], 201);
        } catch (Exception $e) {
            Log::error('Opening a hold failed', ['error' => $e->getMessage()]);

            return $this->fail($e->getMessage(), 500);
        }
    }

    protected function storePooled(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string|max:191',
            'amount' => 'required|numeric|min:0.5',
            'currency' => 'required|string|size:3',
            'flow' => 'nullable|string|max:64',
            'parties' => 'nullable|array|max:20',
            'parties.*' => 'string|starts_with:acct_',
            'on_expiry' => 'nullable|string|max:64',
            'payer_email' => 'nullable|email',
            'success_url' => 'required|url',
            'cancel_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        try {
            $merchant = $this->merchant($request);
            $reference = $request->input('reference');

            $existing = SettlementHold::forMerchant($merchant->id)->where('reference', $reference)->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'data' => $this->present($existing),
                    'message' => 'Hold already exists for this reference.',
                ], 200);
            }

            $parties = (array) $request->input('parties', []);

            // Every party has to be one of this partner's recipient accounts
            // now, rather than surfacing as a failed release weeks later.
            $known = ConnectedAccount::byMerchant($merchant->id)
                ->where('account_type', ConnectedAccount::TYPE_RECIPIENT)
                ->whereIn('account_id', array_values($parties))
                ->pluck('account_id')
                ->all();

            $unknown = array_keys(array_diff($parties, $known));

            if ($unknown) {
                return $this->fail(
                    'These parties are not recipient accounts of yours: ' . implode(', ', $unknown) . '.',
                    422
                );
            }

            $onExpiry = $request->input('on_expiry', 'refund');

            if ($onExpiry !== 'refund' && !array_key_exists($onExpiry, $parties)) {
                return $this->fail('on_expiry must be "refund" or the name of one of the parties.', 422);
            }

            $hold = $this->engine->openPooled(
                $merchant->id,
                $reference,
                (float) $request->input('amount'),
                strtoupper($request->input('currency')),
                $parties,
                $request->input('flow'),
                $onExpiry,
                $request->input('payer_email'),
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json(['status' => 'success', 'data' => $this->present($hold)], 201);
        } catch (Exception $e) {
            Log::error('Opening a pooled hold failed', ['error' => $e->getMessage()]);

            return $this->fail($e->getMessage(), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $merchant = $this->merchant($request);

            $query = SettlementHold::forMerchant($merchant->id)->with(['connectedAccount', 'transfers']);

            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }

            if ($flow = $request->query('flow')) {
                $query->where('flow', $flow);
            }

            if ($request->boolean('expiring')) {
                $query->releasable()
                    ->whereNotNull('hold_expires_at')
                    ->where('hold_expires_at', '<=', now()->addDays(7));
            }

            $holds = $query->orderByRaw('hold_expires_at is null, hold_expires_at asc')
                ->paginate(min((int) $request->query('per_page', 25), 100));

            return response()->json([
                'status' => 'success',
                'data' => collect($holds->items())->map(fn ($h) => $this->present($h)),
                'meta' => [
                    'total' => $holds->total(),
                    'per_page' => $holds->perPage(),
                    'current_page' => $holds->currentPage(),
                    'last_page' => $holds->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        try {
            $hold = $this->find($request, $reference);

            return response()->json([
                'status' => 'success',
                'data' => $this->present($hold, withEvents: true),
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->fail("No hold found for reference {$reference}.", 404);
        } catch (Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    // ─── Conditions ───────────────────────────────────────────────────

    /**
     * Report a milestone: goods shipped, delivered, inspected, accepted,
     * signed off. Rules decide what, if anything, it releases and to whom.
     */
    public function milestone(Request $request, string $reference): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'milestone' => 'required|string|max:64',
            'confirmed_by' => 'nullable|string|max:64',
            'actor' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $milestone = $request->input('milestone');
        $confirmedBy = $request->input('confirmed_by');
        $actor = $request->input('actor');

        return $this->act($request, $reference, function (SettlementHold $hold) use ($milestone, $confirmedBy, $actor) {
            return $this->engine->milestoneReached($hold, $milestone, $confirmedBy, $actor);
        });
    }

    /** Resend any share of a release whose transfer failed. */
    public function retryTransfers(Request $request, string $reference): JsonResponse
    {
        return $this->act($request, $reference, function (SettlementHold $hold) {
            $this->engine->retryFailedTransfers($hold);

            return $hold->fresh();
        });
    }

    public function confirm(Request $request, string $reference): JsonResponse
    {
        return $this->act($request, $reference, function (SettlementHold $hold) {
            return $this->engine->buyerConfirmed($hold);
        });
    }

    public function approve(Request $request, string $reference): JsonResponse
    {
        $actor = $request->input('actor', 'admin');

        return $this->act($request, $reference, function (SettlementHold $hold) use ($actor) {
            return $this->engine->approvedManually($hold, $actor);
        });
    }

    public function dispute(Request $request, string $reference): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $reason = $request->input('reason');
        $actor = $request->input('actor', 'buyer');

        return $this->act($request, $reference, function (SettlementHold $hold) use ($reason, $actor) {
            return $this->engine->raiseDispute($hold, $reason, $actor);
        });
    }

    public function resolveDispute(Request $request, string $reference): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'outcome' => 'required|in:release,refund,resume',
            'actor' => 'required|string|max:100',
            'destinations' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $destinations = $request->input('destinations');

        if ($destinations !== null && ($error = SplitCalculator::validate($destinations))) {
            return $this->fail($error, 422);
        }

        $outcome = $request->input('outcome');
        $actor = $request->input('actor');

        return $this->act($request, $reference, function (SettlementHold $hold) use ($outcome, $actor, $destinations) {
            return $this->engine->resolveDispute($hold, $outcome, $actor, $destinations);
        });
    }

    public function refund(Request $request, string $reference): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $amount = $request->input('amount');
        $reason = $request->input('reason', '');

        return $this->act($request, $reference, function (SettlementHold $hold) use ($amount, $reason) {
            return $this->engine->refund($hold, $amount !== null ? (float) $amount : $hold->remaining(), $reason);
        });
    }

    // ─── Plumbing ─────────────────────────────────────────────────────

    /**
     * Shared wrapper for the action endpoints. A RuntimeException from the
     * engine is a state conflict — releasing a disputed hold, resolving one
     * that is not disputed — so it answers 409 rather than 500.
     */
    protected function act(Request $request, string $reference, callable $action): JsonResponse
    {
        try {
            $hold = $this->find($request, $reference);
            $result = $action($hold);

            return response()->json([
                'status' => 'success',
                'data' => $this->present($result, withEvents: true),
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->fail("No hold found for reference {$reference}.", 404);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (Exception $e) {
            Log::error('Hold action failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return $this->fail($e->getMessage(), 500);
        }
    }

    protected function merchant(Request $request)
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);

        if (!$merchant) {
            throw new ModelNotFoundException('Merchant not found.');
        }

        return $merchant;
    }

    protected function find(Request $request, string $reference): SettlementHold
    {
        return SettlementHold::forMerchant($this->merchant($request)->id)
            ->with(['connectedAccount', 'events', 'transfers'])
            ->where('reference', $reference)
            ->firstOrFail();
    }

    protected function fail(string $message, int $code): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $code);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(SettlementHold $hold, bool $withEvents = false): array
    {
        $data = [
            'reference' => $hold->reference,
            'funding' => $hold->funding ?? SettlementHold::FUNDING_ACCOUNT,
            'flow' => $hold->flow,
            'status' => $hold->status,
            'amount' => (float) $hold->amount,
            'amount_released' => (float) $hold->amount_released,
            'amount_refunded' => (float) $hold->amount_refunded,
            'remaining' => $hold->remaining(),
            'currency' => $hold->currency,
            'seller_account_id' => $hold->connectedAccount->account_id ?? null,
            'held_at' => $hold->held_at?->toIso8601String(),
            'release_by' => $hold->hold_expires_at?->toIso8601String(),
            'released_at' => $hold->released_at?->toIso8601String(),
            'release_reason' => $hold->release_reason,
            'is_disputed' => $hold->isFrozen(),
        ];

        if ($hold->isPooled()) {
            $data['parties'] = $hold->parties ?? [];
            $data['on_expiry'] = $hold->on_expiry;

            if ($hold->status === SettlementHold::STATUS_AWAITING_PAYMENT) {
                $data['payment_url'] = $hold->payment_url;
            }

            $data['transfers'] = $hold->transfers->map(fn ($t) => [
                'release_id' => $t->release_id,
                'to' => $t->destination,
                'account_id' => $t->account_id,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'status' => $t->status,
                'transfer_id' => $t->transfer_id,
                'error' => $t->error,
            ])->values();
        }

        if ($withEvents) {
            $data['events'] = $hold->events->map(fn ($e) => [
                'at' => $e->created_at?->toIso8601String(),
                'event' => $e->event_type,
                'actor' => $e->actor,
                'amount' => $e->amount !== null ? (float) $e->amount : null,
                'note' => $e->note,
            ])->values();
        }

        return $data;
    }
}
