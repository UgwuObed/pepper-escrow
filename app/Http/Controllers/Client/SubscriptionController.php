<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    protected TenantService $tenantService;
    protected SubscriptionService $subscriptionService;

    public function __construct(TenantService $tenantService, SubscriptionService $subscriptionService)
    {
        $this->tenantService = $tenantService;
        $this->subscriptionService = $subscriptionService;
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|integer|exists:subscription_plans,id',
            'customer_email' => 'required|email',
            'customer_name' => 'nullable|string|max:255',
            'start_trial' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $plan = SubscriptionPlan::byMerchant($merchant->id)
                ->active()
                ->findOrFail($request->input('plan_id'));

            $subscription = $this->subscriptionService->subscribe(
                $plan,
                $merchant,
                $request->input('customer_email'),
                $request->input('customer_name'),
                $request->boolean('start_trial'),
            );

            return response()->json(['status' => 'success', 'data' => $subscription], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $subscription = Subscription::byMerchant($merchant->id)
                ->active()
                ->findOrFail($id);

            $subscription->cancel($request->input('reason'));

            return response()->json(['status' => 'success', 'message' => 'Subscription cancelled.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $subscriptions = Subscription::byMerchant($merchant->id)
                ->with(['plan', 'invoices' => fn($q) => $q->latest()->limit(5)])
                ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
                ->when($request->input('customer_email'), fn($q, $e) => $q->where('customer_email', $e))
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'status' => 'success',
                'data' => $subscriptions->items(),
                'meta' => [
                    'current_page' => $subscriptions->currentPage(),
                    'per_page' => $subscriptions->perPage(),
                    'total' => $subscriptions->total(),
                    'last_page' => $subscriptions->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $subscription = Subscription::byMerchant($merchant->id)
                ->with(['plan', 'invoices' => fn($q) => $q->latest()])
                ->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $subscription]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function invoices(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $subscription = Subscription::byMerchant($merchant->id)->findOrFail($id);

            $invoices = $subscription->invoices()
                ->with('transaction')
                ->orderBy('billing_period', 'desc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'status' => 'success',
                'data' => $invoices->items(),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total(),
                    'last_page' => $invoices->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function billNow(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $subscription = Subscription::byMerchant($merchant->id)
                ->active()
                ->findOrFail($id);

            $transaction = $this->subscriptionService->processBilling($subscription);

            if (!$transaction) {
                return response()->json(['status' => 'error', 'message' => 'Subscription is not due for billing or is on trial.'], 400);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction' => $transaction,
                    'subscription' => $subscription->fresh(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
