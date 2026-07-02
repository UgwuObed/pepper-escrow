<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\MerchantBankAccount;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClientSettingsController extends Controller
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

    public function getConfig(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $config = $this->tenantService->getClientConfig($merchant);

            return response()->json([
                'status' => 'success',
                'data' => $config,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateConfig(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'escrow_hold_days' => 'nullable|integer|min:1|max:365',
                'settlement_schedule' => 'nullable|in:manual,daily,weekly,monthly',
                'settlement_day' => 'nullable|integer|min:1|max:31',
                'min_settlement_amount' => 'nullable|numeric|min:0',
                'webhook_url' => 'nullable|url|max:500',
                'auto_release_enabled' => 'nullable|boolean',
                'require_fulfillment_confirmation' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $config = $this->tenantService->updateClientConfig(
                $merchant,
                $request->only([
                    'escrow_hold_days',
                    'settlement_schedule',
                    'settlement_day',
                    'min_settlement_amount',
                    'webhook_url',
                    'auto_release_enabled',
                    'require_fulfillment_confirmation',
                ])
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Configuration updated.',
                'data' => $config,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function listBankAccounts(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $accounts = $merchant->bankAccounts()->orderBy('is_default', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'data' => $accounts,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function createBankAccount(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'bank_name' => 'required|string|max:255',
                'bank_code' => 'required|string|max:50',
                'account_number' => 'required|string|max:20',
                'account_name' => 'required|string|max:255',
                'currency' => 'nullable|string|size:3',
                'is_default' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->only(['bank_name', 'bank_code', 'account_number', 'account_name', 'currency', 'is_default']);
            $data['merchant_id'] = $merchant->id;

            if ($request->boolean('is_default')) {
                $merchant->bankAccounts()->update(['is_default' => false]);
            }

            $account = MerchantBankAccount::create($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account added.',
                'data' => $account,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateBankAccount(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $account = $merchant->bankAccounts()->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'bank_name' => 'nullable|string|max:255',
                'bank_code' => 'nullable|string|max:50',
                'account_number' => 'nullable|string|max:20',
                'account_name' => 'nullable|string|max:255',
                'currency' => 'nullable|string|size:3',
                'is_default' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($request->boolean('is_default')) {
                $merchant->bankAccounts()->where('id', '!=', $id)->update(['is_default' => false]);
            }

            $account->update($request->only([
                'bank_name', 'bank_code', 'account_number', 'account_name', 'currency', 'is_default',
            ]));

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account updated.',
                'data' => $account->fresh(),
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function deleteBankAccount(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $account = $merchant->bankAccounts()->findOrFail($id);
            $account->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account removed.',
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function setDefaultBankAccount(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $account = $merchant->bankAccounts()->findOrFail($id);

            $merchant->bankAccounts()->update(['is_default' => false]);
            $account->update(['is_default' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Default bank account updated.',
                'data' => $account->fresh(),
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function getApiTokenConfig(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $token = $merchant->apiToken;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'payment_gateway' => $token->payment_gateway,
                    'gateway_config' => $token->gateway_config,
                    'config' => $token->config,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateApiTokenConfig(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $token = $merchant->apiToken;

            $validator = Validator::make($request->all(), [
                'payment_gateway' => 'nullable|in:paystack,stripe,seerbit,flutterwave',
                'gateway_config' => 'nullable|json',
                'config' => 'nullable|json',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $update = [];

            if ($request->filled('payment_gateway')) {
                $update['payment_gateway'] = $request->input('payment_gateway');
            }

            if ($request->filled('gateway_config')) {
                $update['gateway_config'] = json_decode($request->input('gateway_config'), true);
            }

            if ($request->filled('config')) {
                $update['config'] = json_decode($request->input('config'), true);
            }

            $token->update($update);

            return response()->json([
                'status' => 'success',
                'message' => 'API token config updated.',
                'data' => $token->fresh(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
