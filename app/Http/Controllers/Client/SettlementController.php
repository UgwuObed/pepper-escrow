<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\MerchantBankAccount;
use App\Models\Settlement;
use App\Services\SettlementService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SettlementController extends Controller
{
    protected TenantService $tenantService;
    protected SettlementService $settlementService;

    public function __construct(TenantService $tenantService, SettlementService $settlementService)
    {
        $this->tenantService = $tenantService;
        $this->settlementService = $settlementService;
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $settlements = Settlement::byMerchant($merchant->id)
                ->withCount('items')
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'status' => 'success',
                'data' => $settlements->items(),
                'meta' => [
                    'current_page' => $settlements->currentPage(),
                    'per_page' => $settlements->perPage(),
                    'total' => $settlements->total(),
                    'last_page' => $settlements->lastPage(),
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

            $settlement = Settlement::byMerchant($merchant->id)
                ->with('items.transaction')
                ->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $settlement]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $transactions = $this->settlementService->getSettlableTransactions(
                $merchant,
                $request->input('currency'),
            );

            $totalAmount = array_sum(array_map(fn($t) => (float) $t->amount, $transactions));
            $totalCommission = array_sum(array_map(fn($t) => (float) ($t->commission_amount ?? 0), $transactions));

            $bankAccount = MerchantBankAccount::where('merchant_id', $merchant->id)
                ->where('status', true)
                ->where('is_default', true)
                ->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'settlable_count' => count($transactions),
                    'total_amount' => $totalAmount,
                    'total_commission' => $totalCommission,
                    'net_amount' => $totalAmount - $totalCommission,
                    'currency' => $request->input('currency', 'NGN'),
                    'has_default_bank' => $bankAccount !== null,
                    'default_bank' => $bankAccount,
                    'transactions' => array_map(fn($t) => [
                        'id' => $t->id,
                        'transcode' => $t->transcode,
                        'amount' => (float) $t->amount,
                        'commission' => (float) ($t->commission_amount ?? 0),
                        'net' => (float) ($t->net_amount ?? $t->amount) - (float) ($t->commission_amount ?? 0),
                        'payment_date' => $t->payment_date,
                        'customer' => $t->customer_email,
                    ], $transactions),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'currency' => 'nullable|string|size:3',
            'gateway' => 'nullable|string',
            'auto_process' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $transactions = $this->settlementService->getSettlableTransactions(
                $merchant,
                $request->input('currency'),
            );

            if (empty($transactions)) {
                return response()->json(['status' => 'error', 'message' => 'No settlable transactions found.'], 400);
            }

            $settlement = $this->settlementService->createSettlementBatch(
                $merchant,
                $transactions,
                $request->input('gateway'),
                $request->input('currency', 'NGN'),
            );

            if ($request->boolean('auto_process', true)) {
                $settlement = $this->settlementService->processSettlement($settlement);
            }

            return response()->json(['status' => 'success', 'data' => $settlement->load('items.transaction')], 201);
        } catch (Exception $e) {
            Log::error('Settlement creation error', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function process(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $settlement = Settlement::byMerchant($merchant->id)->findOrFail($id);

            $settlement = $this->settlementService->processSettlement($settlement);

            return response()->json(['status' => 'success', 'data' => $settlement->load('items.transaction')]);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function webhook(Request $request, string $gateway): JsonResponse
    {
        try {
            $payload = $request->all();
            Log::info("Settlement webhook received from {$gateway}", $payload);

            $settlement = match ($gateway) {
                'paystack' => $this->settlementService->handlePaystackTransferWebhook($payload),
                'flutterwave' => $this->settlementService->handleFlutterwaveTransferWebhook($payload),
                default => throw new \Exception("Unsupported gateway: {$gateway}"),
            };

            if ($settlement) {
                return response()->json(['status' => 'success', 'batch' => $settlement->batch_number]);
            }

            return response()->json(['status' => 'ok', 'message' => 'No matching settlement found']);
        } catch (Exception $e) {
            Log::error('Settlement webhook error', ['gateway' => $gateway, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
