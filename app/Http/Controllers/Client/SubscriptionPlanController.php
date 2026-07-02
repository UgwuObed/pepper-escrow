<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $plans = SubscriptionPlan::byMerchant($merchant->id)
                ->when($request->boolean('active_only'), fn($q) => $q->active())
                ->orderBy('name')
                ->get();

            return response()->json(['status' => 'success', 'data' => $plans]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'billing_cycle' => 'required|in:daily,weekly,monthly,yearly',
            'cycle_interval' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:0',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
            'features' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $slug = Str::slug($request->input('name')) . '-' . Str::random(6);

            $plan = SubscriptionPlan::create([
                'merchant_id' => $merchant->id,
                'transaction_type_id' => $request->input('transaction_type_id'),
                'name' => $request->input('name'),
                'slug' => $slug,
                'description' => $request->input('description'),
                'amount' => $request->input('amount'),
                'currency' => $request->input('currency', 'NGN'),
                'billing_cycle' => $request->input('billing_cycle'),
                'cycle_interval' => $request->input('cycle_interval', 1),
                'trial_days' => $request->input('trial_days', 0),
                'features' => $request->input('features'),
            ]);

            return response()->json(['status' => 'success', 'data' => $plan], 201);
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

            $plan = SubscriptionPlan::byMerchant($merchant->id)->with('transactionType')->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $plan]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'billing_cycle' => 'nullable|in:daily,weekly,monthly,yearly',
            'cycle_interval' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
            'features' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $plan = SubscriptionPlan::byMerchant($merchant->id)->findOrFail($id);
            $plan->update($request->only([
                'name', 'description', 'amount', 'currency',
                'billing_cycle', 'cycle_interval', 'trial_days',
                'is_active', 'transaction_type_id', 'features',
            ]));

            return response()->json(['status' => 'success', 'data' => $plan]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $plan = SubscriptionPlan::byMerchant($merchant->id)->findOrFail($id);

            if ($plan->subscriptions()->whereIn('status', ['active', 'paused'])->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Plan has active subscriptions. Deactivate it instead.'], 409);
            }

            $plan->delete();

            return response()->json(['status' => 'success', 'message' => 'Plan deleted.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
