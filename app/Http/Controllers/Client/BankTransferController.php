<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Services\TenantService;
use App\Services\VirtualAccountService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BankTransferController extends Controller
{
    protected TenantService $tenantService;
    protected VirtualAccountService $vaService;

    public function __construct(TenantService $tenantService, VirtualAccountService $vaService)
    {
        $this->tenantService = $tenantService;
        $this->vaService = $vaService;
    }

    public function assign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_code' => 'required|string|exists:transactions,transcode',
            'gateway' => 'nullable|in:paystack,flutterwave',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $transaction = Transaction::where('transcode', $request->input('transaction_code'))
                ->where('appid', $merchant->id)
                ->firstOrFail();

            $va = $this->vaService->assignToTransaction(
                $transaction,
                $merchant,
                $request->input('gateway'),
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $va->id,
                    'account_number' => $va->account_number,
                    'account_name' => $va->account_name,
                    'bank_name' => $va->bank_name,
                    'amount' => (float) $transaction->amount,
                    'reference' => $transaction->transcode,
                    'expires_at' => $va->expires_at?->toIso8601String(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Bank transfer assignment error', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function info(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $va = VirtualAccount::where('merchant_id', $merchant->id)
                ->active()
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $va->id,
                    'account_number' => $va->account_number,
                    'account_name' => $va->account_name,
                    'bank_name' => $va->bank_name,
                    'customer_email' => $va->customer_email,
                    'gateway' => $va->gateway,
                    'status' => $va->status,
                    'created_at' => $va->created_at->toIso8601String(),
                ],
            ]);
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

            $vas = VirtualAccount::where('merchant_id', $merchant->id)
                ->with('transaction')
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'status' => 'success',
                'data' => $vas->items(),
                'meta' => [
                    'current_page' => $vas->currentPage(),
                    'per_page' => $vas->perPage(),
                    'total' => $vas->total(),
                    'last_page' => $vas->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $va = VirtualAccount::where('merchant_id', $merchant->id)->findOrFail($id);

            $this->vaService->deactivate($va);

            return response()->json(['status' => 'success', 'message' => 'Virtual account deactivated.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function webhook(Request $request, string $gateway): JsonResponse
    {
        try {
            $payload = $request->all();
            Log::info("Bank transfer webhook received from {$gateway}", $payload);

            $transaction = match ($gateway) {
                'paystack' => $this->vaService->handlePaystackCredit($payload),
                'flutterwave' => $this->vaService->handleFlutterwaveCredit($payload),
                default => throw new \Exception("Unsupported gateway: {$gateway}"),
            };

            if ($transaction) {
                return response()->json(['status' => 'success', 'transaction' => $transaction->transcode]);
            }

            return response()->json(['status' => 'ok', 'message' => 'No matching transaction found']);
        } catch (Exception $e) {
            Log::error('Bank transfer webhook error', ['gateway' => $gateway, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
