<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\TenantService;
use App\Services\WalletService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    protected TenantService $tenantService;
    protected WalletService $walletService;

    public function __construct(TenantService $tenantService, WalletService $walletService)
    {
        $this->tenantService = $tenantService;
        $this->walletService = $walletService;
    }

    protected function getMerchant(Request $request)
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);
        if (!$merchant) {
            abort(401, 'Unauthenticated merchant.');
        }
        return $merchant;
    }

    protected function walletResponse(Wallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'user_identifier' => $wallet->user_identifier,
            'currency' => $wallet->currency,
            'type' => $wallet->type,
            'label' => $wallet->label,
            'balance' => (float) $wallet->balance,
            'ledger_balance' => (float) $wallet->ledger_balance,
            'hold_balance' => (float) $wallet->hold_balance,
            'available_balance' => (float) ($wallet->ledger_balance - $wallet->hold_balance),
            'status' => $wallet->status,
            'created_at' => $wallet->created_at,
        ];
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'user_identifier' => 'required|string|max:255',
                'currency' => 'nullable|string|size:3',
                'type' => 'nullable|in:fiat,reward',
                'label' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $wallet = $this->walletService->createWallet(
                merchantId: $merchant->id,
                userIdentifier: $request->input('user_identifier'),
                currency: $request->input('currency', 'NGN'),
                type: $request->input('type', 'fiat'),
                label: $request->input('label'),
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet created.',
                'data' => $this->walletResponse($wallet),
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $wallets = Wallet::byMerchant($merchant->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn($w) => $this->walletResponse($w));

            return response()->json(['status' => 'success', 'data' => $wallets]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $this->walletResponse($wallet),
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function getBalance(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $this->walletService->getBalance($wallet),
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);

            $perPage = $request->input('per_page', 20);
            $txns = $wallet->transactions()
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json(['status' => 'success', 'data' => $txns]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function credit(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'reference_type' => 'required|string|max:100',
                'reference_id' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $txn = $this->walletService->credit(
                wallet: $wallet,
                amount: (float) $request->input('amount'),
                referenceType: $request->input('reference_type'),
                referenceId: $request->input('reference_id'),
                description: $request->input('description', ''),
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet credited.',
                'data' => [
                    'transaction' => $txn,
                    'balance' => $this->walletService->getBalance($wallet),
                ],
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function debit(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'reference_type' => 'required|string|max:100',
                'reference_id' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $txn = $this->walletService->debit(
                wallet: $wallet,
                amount: (float) $request->input('amount'),
                referenceType: $request->input('reference_type'),
                referenceId: $request->input('reference_id'),
                description: $request->input('description', ''),
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet debited.',
                'data' => [
                    'transaction' => $txn,
                    'balance' => $this->walletService->getBalance($wallet),
                ],
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function transfer(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'from_wallet_id' => 'required|integer|exists:wallets,id',
                'to_wallet_id' => 'required|integer|exists:wallets,id',
                'amount' => 'required|numeric|min:0.01',
                'reference_type' => 'required|string|max:100',
                'reference_id' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $from = Wallet::byMerchant($merchant->id)->findOrFail($request->input('from_wallet_id'));
            $to = Wallet::byMerchant($merchant->id)->findOrFail($request->input('to_wallet_id'));

            $result = $this->walletService->transfer(
                from: $from,
                to: $to,
                amount: (float) $request->input('amount'),
                referenceType: $request->input('reference_type'),
                referenceId: $request->input('reference_id'),
                description: $request->input('description', ''),
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Transfer completed.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function findByUser(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'user_identifier' => 'required|string|max:255',
                'currency' => 'nullable|string|size:3',
                'type' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $wallet = Wallet::findByUser(
                userIdentifier: $request->input('user_identifier'),
                currency: $request->input('currency', 'NGN'),
                type: $request->input('type', 'fiat'),
                merchantId: $merchant->id,
            );

            if (!$wallet) {
                return response()->json(['status' => 'error', 'message' => 'Wallet not found.'], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->walletResponse($wallet),
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
