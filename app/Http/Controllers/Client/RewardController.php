<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\RewardBalance;
use App\Models\RewardProgram;
use App\Models\RewardTransaction;
use App\Services\RewardService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RewardController extends Controller
{
    protected TenantService $tenantService;
    protected RewardService $rewardService;

    public function __construct(TenantService $tenantService, RewardService $rewardService)
    {
        $this->tenantService = $tenantService;
        $this->rewardService = $rewardService;
    }

    // ─── Reward Programs ──────────────────────────────────────────────

    public function listPrograms(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $programs = RewardProgram::byMerchant($merchant->id)
                ->when($request->boolean('active_only'), fn($q) => $q->active())
                ->orderBy('name')
                ->get();

            return response()->json(['status' => 'success', 'data' => $programs]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function createProgram(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'reward_type' => 'required|in:points,cashback,discount_percentage,discount_flat',
            'reward_value' => 'required|numeric|min:0',
            'min_transaction_amount' => 'nullable|numeric|min:0',
            'applicable_type_ids' => 'nullable|array',
            'applicable_type_ids.*' => 'integer|exists:transaction_types,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $program = RewardProgram::create([
                'merchant_id' => $merchant->id,
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'reward_type' => $request->input('reward_type'),
                'reward_value' => $request->input('reward_value'),
                'min_transaction_amount' => $request->input('min_transaction_amount'),
                'applicable_type_ids' => $request->input('applicable_type_ids'),
                'starts_at' => $request->input('starts_at'),
                'ends_at' => $request->input('ends_at'),
            ]);

            return response()->json(['status' => 'success', 'data' => $program], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateProgram(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'reward_type' => 'nullable|in:points,cashback,discount_percentage,discount_flat',
            'reward_value' => 'nullable|numeric|min:0',
            'min_transaction_amount' => 'nullable|numeric|min:0',
            'applicable_type_ids' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $program = RewardProgram::byMerchant($merchant->id)->findOrFail($id);
            $program->update($request->only([
                'name', 'description', 'reward_type', 'reward_value',
                'min_transaction_amount', 'applicable_type_ids', 'is_active',
                'starts_at', 'ends_at',
            ]));

            return response()->json(['status' => 'success', 'data' => $program]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteProgram(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $program = RewardProgram::byMerchant($merchant->id)->findOrFail($id);
            $program->delete();

            return response()->json(['status' => 'success', 'message' => 'Program deleted.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Reward Balances ──────────────────────────────────────────────

    public function getBalance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $balances = $this->rewardService->getBalances($merchant, $request->input('customer_email'));

            return response()->json(['status' => 'success', 'data' => $balances]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function customerHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $history = $this->rewardService->getCustomerHistory($merchant, $request->input('customer_email'));

            return response()->json(['status' => 'success', 'data' => $history]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function awardManual(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email' => 'required|email',
            'reward_type' => 'required|in:points,cashback',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $rt = $this->rewardService->award(
                $merchant,
                $request->input('customer_email'),
                $request->input('reward_type'),
                (float) $request->input('amount'),
                null,
                $request->input('description'),
            );

            return response()->json(['status' => 'success', 'data' => $rt], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function redeem(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email' => 'required|email',
            'reward_type' => 'required|in:points,cashback',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $rt = $this->rewardService->redeem(
                $merchant,
                $request->input('customer_email'),
                $request->input('reward_type'),
                (float) $request->input('amount'),
                $request->input('description'),
            );

            return response()->json(['status' => 'success', 'data' => $rt]);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
