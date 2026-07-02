<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CommissionRuleController extends Controller
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    protected function getMerchant(Request $request)
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);
        if (!$merchant) {
            abort(401, 'Unauthenticated merchant.');
        }
        return $merchant;
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $rules = CommissionRule::where('merchant_id', $merchant->id)
                ->with('transactionType')
                ->orderBy('priority', 'desc')
                ->get();

            return response()->json(['status' => 'success', 'data' => $rules]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'transaction_type_id' => 'required|integer|exists:transaction_types,id',
                'name' => 'nullable|string|max:255',
                'rate_type' => 'required|in:percentage,flat',
                'rate_value' => 'required|numeric|min:0',
                'cap_amount' => 'nullable|numeric|min:0',
                'min_amount' => 'nullable|numeric|min:0',
                'max_amount' => 'nullable|numeric|min:0',
                'priority' => 'nullable|integer|min:0',
                'payer' => 'nullable|in:merchant,customer',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $data = $request->only([
                'transaction_type_id', 'name', 'rate_type', 'rate_value',
                'cap_amount', 'min_amount', 'max_amount', 'priority', 'payer',
            ]);
            $data['merchant_id'] = $merchant->id;

            $rule = CommissionRule::create($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Commission rule created.',
                'data' => $rule->load('transactionType'),
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $rule = CommissionRule::where('merchant_id', $merchant->id)->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'rate_type' => 'nullable|in:percentage,flat',
                'rate_value' => 'nullable|numeric|min:0',
                'cap_amount' => 'nullable|numeric|min:0',
                'min_amount' => 'nullable|numeric|min:0',
                'max_amount' => 'nullable|numeric|min:0',
                'priority' => 'nullable|integer|min:0',
                'payer' => 'nullable|in:merchant,customer',
                'status' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $rule->update($request->only([
                'name', 'rate_type', 'rate_value', 'cap_amount',
                'min_amount', 'max_amount', 'priority', 'payer', 'status',
            ]));

            return response()->json([
                'status' => 'success',
                'message' => 'Commission rule updated.',
                'data' => $rule->fresh()->load('transactionType'),
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $rule = CommissionRule::where('merchant_id', $merchant->id)
                ->with('transactionType')
                ->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $rule]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $rule = CommissionRule::where('merchant_id', $merchant->id)->findOrFail($id);
            $rule->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Commission rule deleted.',
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function calculate(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'transaction_type_id' => 'required|integer|exists:transaction_types,id',
                'amount' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $commissionService = app(\App\Services\CommissionService::class);
            $result = $commissionService->calculate(
                merchant: $merchant,
                transactionTypeId: (int) $request->input('transaction_type_id'),
                amount: (float) $request->input('amount'),
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'amount' => (float) $request->input('amount'),
                    'commission' => $result['commission'],
                    'net' => $result['net'],
                    'rate_type' => $result['rule']?->rate_type,
                    'rate_value' => $result['rule']?->rate_value,
                    'rule_name' => $result['rule']?->name,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
