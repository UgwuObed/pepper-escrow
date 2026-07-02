<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ListingFee;
use App\Services\ListingFeeService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ListingFeeController extends Controller
{
    protected TenantService $tenantService;
    protected ListingFeeService $listingFeeService;

    public function __construct(TenantService $tenantService, ListingFeeService $listingFeeService)
    {
        $this->tenantService = $tenantService;
        $this->listingFeeService = $listingFeeService;
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $fees = ListingFee::byMerchant($merchant->id)
                ->with('transactionType')
                ->when($request->boolean('active_only'), fn($q) => $q->active())
                ->orderBy('name')
                ->get();

            return response()->json(['status' => 'success', 'data' => $fees]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fee_type' => 'required|in:flat,percentage',
            'fee_value' => 'required|numeric|min:0',
            'cap_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $fee = ListingFee::create([
                'merchant_id' => $merchant->id,
                'transaction_type_id' => $request->input('transaction_type_id'),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'fee_type' => $request->input('fee_type'),
                'fee_value' => $request->input('fee_value'),
                'cap_amount' => $request->input('cap_amount'),
                'currency' => $request->input('currency', 'NGN'),
            ]);

            return response()->json(['status' => 'success', 'data' => $fee], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'fee_type' => 'nullable|in:flat,percentage',
            'fee_value' => 'nullable|numeric|min:0',
            'cap_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $fee = ListingFee::byMerchant($merchant->id)->findOrFail($id);
            $fee->update($request->only([
                'name', 'description', 'fee_type', 'fee_value',
                'cap_amount', 'currency', 'transaction_type_id', 'is_active',
            ]));

            return response()->json(['status' => 'success', 'data' => $fee]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $fee = ListingFee::byMerchant($merchant->id)->findOrFail($id);
            $fee->delete();

            return response()->json(['status' => 'success', 'message' => 'Listing fee deleted.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $amount = (float) $request->input('amount');
            $fees = $this->listingFeeService->getApplicableFees($merchant, $request->input('transaction_type_id'));

            $results = array_map(function ($fee) use ($amount) {
                $feeModel = ListingFee::find($fee['id']);
                return [
                    'id' => $fee['id'],
                    'name' => $fee['name'],
                    'fee_type' => $fee['fee_type'],
                    'fee_value' => (float) $fee['fee_value'],
                    'calculated' => $feeModel ? $feeModel->calculateFee($amount) : 0,
                ];
            }, $fees);

            return response()->json(['status' => 'success', 'data' => $results]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
