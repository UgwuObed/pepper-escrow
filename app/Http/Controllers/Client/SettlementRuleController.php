<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SettlementRule;
use App\Services\TenantService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Partners configure what releases their held payments.
 *
 * Rules are per merchant and are tried in priority order for a given
 * condition. Validation is strict about the shape of each condition, since a
 * rule that looks saved but never fires is money sitting in a seller's
 * balance with nobody wondering why.
 */
class SettlementRuleController extends Controller
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $rules = SettlementRule::forMerchant($this->merchantId($request))
                ->orderBy('priority')
                ->get()
                ->map(fn ($r) => $this->present($r));

            return response()->json(['status' => 'success', 'data' => $rules]);
        } catch (ModelNotFoundException $e) {
            return $this->fail('Merchant not found.', 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'condition_type' => ['required', 'in:' . implode(',', SettlementRule::CONDITIONS)],
            'days' => 'required_if:condition_type,elapsed_days|nullable|integer|min:1|max:730',
            'release_percentage' => 'nullable|integer|min:1|max:100',
            'priority' => 'nullable|integer|min:1|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        try {
            $merchantId = $this->merchantId($request);

            if ($error = $this->daysWithinStripeLimit($request)) {
                return $this->fail($error, 422);
            }

            $rule = SettlementRule::create([
                'merchant_id' => $merchantId,
                'name' => $request->input('name'),
                'condition_type' => $request->input('condition_type'),
                'config' => $this->config($request),
                'release_percentage' => (int) $request->input('release_percentage', 100),
                'priority' => (int) $request->input('priority', 100),
                'is_active' => $request->boolean('is_active', true),
            ]);

            return response()->json(['status' => 'success', 'data' => $this->present($rule)], 201);
        } catch (ModelNotFoundException $e) {
            return $this->fail('Merchant not found.', 404);
        } catch (Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:191',
            'days' => 'nullable|integer|min:1|max:730',
            'release_percentage' => 'nullable|integer|min:1|max:100',
            'priority' => 'nullable|integer|min:1|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        try {
            $rule = SettlementRule::forMerchant($this->merchantId($request))->findOrFail($id);

            if ($request->filled('days')) {
                if ($error = $this->daysWithinStripeLimit($request)) {
                    return $this->fail($error, 422);
                }
                $rule->config = array_merge($rule->config ?? [], ['days' => (int) $request->input('days')]);
            }

            foreach (['name', 'release_percentage', 'priority'] as $field) {
                if ($request->filled($field)) {
                    $rule->{$field} = $request->input($field);
                }
            }

            if ($request->has('is_active')) {
                $rule->is_active = $request->boolean('is_active');
            }

            $rule->save();

            return response()->json(['status' => 'success', 'data' => $this->present($rule)]);
        } catch (ModelNotFoundException $e) {
            return $this->fail("Rule {$id} not found.", 404);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $rule = SettlementRule::forMerchant($this->merchantId($request))->findOrFail($id);

            // Deactivated rather than deleted: released holds point at the
            // rule that released them, and that trail should not lose its
            // explanation because someone tidied up.
            $rule->update(['is_active' => false]);

            return response()->json([
                'status' => 'success',
                'message' => 'Rule deactivated. Existing holds keep their reference to it.',
                'data' => $this->present($rule),
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->fail("Rule {$id} not found.", 404);
        }
    }

    /**
     * A timer longer than Stripe will hold the money is a rule that can never
     * fire in time; the sweep would force release first and the partner would
     * never understand why.
     */
    protected function daysWithinStripeLimit(Request $request): ?string
    {
        $days = (int) $request->input('days', 0);

        if ($days > 0 && $days >= 90) {
            return 'A timer of ' . $days . ' days may exceed the Stripe holding limit, which is 90 days '
                . 'outside the US. Holds would be force released before this rule fires.';
        }

        return null;
    }

    protected function config(Request $request): array
    {
        return $request->input('condition_type') === SettlementRule::CONDITION_ELAPSED_DAYS
            ? ['days' => (int) $request->input('days')]
            : [];
    }

    protected function merchantId(Request $request): int
    {
        $merchant = $this->tenantService->getMerchantFromRequest($request);

        if (!$merchant) {
            throw new ModelNotFoundException('Merchant not found.');
        }

        return $merchant->id;
    }

    protected function fail(string $message, int $code): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $code);
    }

    protected function present(SettlementRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'condition_type' => $rule->condition_type,
            'days' => $rule->condition_type === SettlementRule::CONDITION_ELAPSED_DAYS ? $rule->days() : null,
            'release_percentage' => (int) $rule->release_percentage,
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
        ];
    }
}
