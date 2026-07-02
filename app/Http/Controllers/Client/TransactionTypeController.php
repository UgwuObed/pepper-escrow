<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\TransactionType;
use App\Services\CommissionService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionTypeController extends Controller
{
    protected TenantService $tenantService;
    protected CommissionService $commissionService;

    public function __construct(TenantService $tenantService, CommissionService $commissionService)
    {
        $this->tenantService = $tenantService;
        $this->commissionService = $commissionService;
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
            $types = TransactionType::where('merchant_id', $merchant->id)
                ->orderBy('name')
                ->get();

            return response()->json(['status' => 'success', 'data' => $types]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'supports_escrow' => 'nullable|boolean',
                'requires_fulfillment' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $slug = Str::slug($request->input('name'));

            $existing = TransactionType::where('merchant_id', $merchant->id)
                ->where('slug', $slug)
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A transaction type with this name already exists.',
                ], 409);
            }

            $type = TransactionType::create([
                'merchant_id' => $merchant->id,
                'name' => $request->input('name'),
                'slug' => $slug,
                'description' => $request->input('description'),
                'supports_escrow' => $request->boolean('supports_escrow', true),
                'requires_fulfillment' => $request->boolean('requires_fulfillment', true),
            ]);

            $this->commissionService->seedDefaults($merchant);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction type created.',
                'data' => $type,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $type = TransactionType::where('merchant_id', $merchant->id)->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'supports_escrow' => 'nullable|boolean',
                'requires_fulfillment' => 'nullable|boolean',
                'status' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }

            $update = $request->only(['name', 'description', 'supports_escrow', 'requires_fulfillment', 'status']);

            if ($request->filled('name')) {
                $update['slug'] = Str::slug($request->input('name'));
            }

            $type->update($update);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction type updated.',
                'data' => $type->fresh(),
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
            $type = TransactionType::where('merchant_id', $merchant->id)->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $type]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);
            $type = TransactionType::where('merchant_id', $merchant->id)->findOrFail($id);

            $typeCount = $type->transactions()->count();
            if ($typeCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot delete: {$typeCount} transactions use this type.",
                ], 409);
            }

            $type->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction type deleted.',
            ]);
        } catch (Exception $e) {
            $code = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function seedDefaults(Request $request): JsonResponse
    {
        try {
            $merchant = $this->getMerchant($request);

            $existingCount = TransactionType::where('merchant_id', $merchant->id)->count();
            if ($existingCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction types already exist. Delete them first if you want to reseed defaults.',
                ], 409);
            }

            foreach (TransactionType::getDefaults($merchant->id) as $default) {
                TransactionType::create($default);
            }

            $this->commissionService->seedDefaults($merchant);

            $types = TransactionType::where('merchant_id', $merchant->id)->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Default transaction types seeded.',
                'data' => $types,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
