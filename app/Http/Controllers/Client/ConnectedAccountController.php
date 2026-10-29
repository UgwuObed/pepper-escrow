<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\VerifiesGatewayWebhooks;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\SettlementHold;
use App\Services\ConnectedAccountService;
use App\Services\SellerDashboardService;
use App\Services\SettlementRulesEngine;
use App\Services\TenantService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConnectedAccountController extends Controller
{
    use VerifiesGatewayWebhooks;

    protected TenantService $tenantService;
    protected ConnectedAccountService $accounts;

    public function __construct(TenantService $tenantService, ConnectedAccountService $accounts)
    {
        $this->tenantService = $tenantService;
        $this->accounts = $accounts;
    }

    /**
     * Create an account holder's Stripe account and return a link for them to
     * finish Stripe's own onboarding.
     *
     * `email` is the neutral name; `seller_email` is still accepted so
     * existing integrations keep working.
     */
    public function create(Request $request): JsonResponse
    {
        if (!$request->filled('email') && $request->filled('seller_email')) {
            $request->merge(['email' => $request->input('seller_email')]);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'country' => 'required|string|size:2',
            'type' => 'nullable|in:' . implode(',', ConnectedAccount::TYPES),
            'label' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $account = $this->accounts->onboard(
                $merchant,
                $request->input('email'),
                $request->input('country'),
                $request->input('type', ConnectedAccount::TYPE_HOLDING),
                $request->input('label'),
            );

            $link = $this->accounts->onboardingLink($account);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'account' => $this->present($account),
                    'onboarding_url' => $link['url'],
                    'onboarding_expires_at' => $link['expires_at'],
                ],
            ], 201);
        } catch (Exception $e) {
            Log::error('Connected account creation failed', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request): JsonResponse
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);
        if (!$merchant) {
            return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
        }

        $accounts = ConnectedAccount::byMerchant($merchant->id)
            ->when($request->query('type'), fn ($q, $type) => $q->where('account_type', $type))
            ->get()
            ->map(fn (ConnectedAccount $a) => $this->present($a));

        return response()->json(['status' => 'success', 'data' => $accounts]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $account = $this->findForMerchant($request, $id);

            return response()->json(['status' => 'success', 'data' => $this->present($account)]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => "Connected account {$id} not found."], 404);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * A fresh onboarding link. Stripe's expire in minutes, so sellers who
     * abandon the flow need a new one rather than the stored one.
     */
    public function onboardingLink(Request $request, int $id): JsonResponse
    {
        try {
            $account = $this->findForMerchant($request, $id);
            $link = $this->accounts->onboardingLink($account);

            return response()->json([
                'status' => 'success',
                'data' => ['onboarding_url' => $link['url'], 'expires_at' => $link['expires_at']],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => "Connected account {$id} not found."], 404);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        try {
            $account = $this->accounts->syncStatus($this->findForMerchant($request, $id));

            return response()->json(['status' => 'success', 'data' => $this->present($account)]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => "Connected account {$id} not found."], 404);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Stripe Connect account and payout events. Public, so the signature is
     * the only thing establishing who sent it.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            if (!$this->webhookIsAuthentic($request, 'stripe_connect')) {
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            $payload = $request->all();
            $type = $payload['type'] ?? '';

            Log::info('Stripe Connect webhook', ['type' => $type]);

            if ($type === 'account.updated') {
                $account = $this->accounts->handleAccountUpdated($payload);

                return response()->json([
                    'status' => 'success',
                    'account' => $account?->account_id,
                ]);
            }

            // A pooled hold's payment has landed in the platform balance.
            // Platform events carry no "account"; one that does is a direct
            // charge on a holding account and is not ours to act on here.
            if ($type === 'checkout.session.completed' && empty($payload['account'])) {
                return $this->pooledPaymentCompleted($payload['data']['object'] ?? []);
            }

            // Payout events belong to the release side of the flow, which the
            // rules engine owns. Acknowledged here so Stripe stops retrying.
            return response()->json(['status' => 'ok', 'message' => "Unhandled event {$type}"]);
        } catch (Exception $e) {
            Log::error('Stripe Connect webhook error', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Everything the seller can see about their own money.
     *
     * They have no Stripe dashboard under this configuration, so this is the
     * only place they can look.
     */
    public function dashboard(Request $request, int $id, SellerDashboardService $dashboard): JsonResponse
    {
        try {
            $account = $this->findForMerchant($request, $id);

            return response()->json(['status' => 'success', 'data' => $dashboard->forAccount($account)]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => "Connected account {$id} not found."], 404);
        } catch (Exception $e) {
            Log::error('Seller dashboard failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * A short-lived secret for Stripe's embedded components.
     *
     * Bank details and identity data are things Peppa is barred from
     * touching, so the seller edits them through Stripe's own UI embedded in
     * the partner's page.
     */
    public function accountSession(Request $request, int $id): JsonResponse
    {
        try {
            $account = $this->findForMerchant($request, $id);

            $components = $request->input('components', ['payouts', 'account_management']);

            if (!is_array($components) || empty($components)) {
                return response()->json(['status' => 'error', 'message' => 'components must be a non-empty array.'], 422);
            }

            $session = $this->accounts->embeddedSession($account, $components);

            return response()->json(['status' => 'success', 'data' => $session]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => "Connected account {$id} not found."], 404);
        } catch (Exception $e) {
            Log::error('Account session failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    protected function findForMerchant(Request $request, int $id): ConnectedAccount
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);

        if (!$merchant) {
            throw new ModelNotFoundException();
        }

        return ConnectedAccount::byMerchant($merchant->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pooledPaymentCompleted(array $session): JsonResponse
    {
        $holdId = $session['metadata']['peppa_hold_id'] ?? null;
        $paymentIntent = $session['payment_intent'] ?? null;

        if (!$holdId || !is_string($paymentIntent)) {
            return response()->json(['status' => 'ok', 'message' => 'Not a pooled hold payment']);
        }

        $hold = SettlementHold::where('id', $holdId)
            ->where('checkout_session_id', $session['id'] ?? null)
            ->first();

        if (!$hold) {
            Log::warning('Pooled payment for an unknown hold', ['hold_id' => $holdId]);

            return response()->json(['status' => 'ok', 'message' => 'Unknown hold']);
        }

        $hold = app(SettlementRulesEngine::class)->paymentReceived($hold, $paymentIntent);

        return response()->json(['status' => 'success', 'hold' => $hold->reference, 'hold_status' => $hold->status]);
    }

    protected function present(ConnectedAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_id' => $account->account_id,
            'type' => $account->account_type ?? ConnectedAccount::TYPE_HOLDING,
            'label' => $account->label,
            'email' => $account->seller_email,
            'seller_email' => $account->seller_email,
            'country' => $account->country,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'holding_period_days' => $account->holding_period_days,
            'can_hold_funds' => $account->canHoldFunds(),
            'hold_blockers' => $account->holdBlockers(),
            'can_receive_transfers' => $account->canReceiveTransfers(),
            'transfer_blockers' => $account->isRecipient() ? $account->transferBlockers() : [],
            'requirements_due' => $account->requirements_due ?? [],
            'status_synced_at' => $account->status_synced_at,
        ];
    }
}
