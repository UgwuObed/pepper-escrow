<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SettlementRule;
use App\Services\SplitCalculator;
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
            'flow' => 'nullable|string|max:64',
            'condition_type' => ['required', 'in:' . implode(',', SettlementRule::CONDITIONS)],
            'days' => 'required_if:condition_type,elapsed_days|nullable|integer|min:1|max:730',
            'milestone' => 'required_if:condition_type,milestone|nullable|string|max:64',
            'confirmed_by' => 'nullable|string|max:64',
            'release_percentage' => 'nullable|integer|min:1|max:100',
            'destinations' => 'nullable|array',
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

            if ($request->has('destinations') && ($error = SplitCalculator::validate($request->input('destinations')))) {
                return $this->fail($error, 422);
            }

            $rule = SettlementRule::create([
                'merchant_id' => $merchantId,
                'name' => $request->input('name'),
                'flow' => $request->input('flow'),
                'condition_type' => $request->input('condition_type'),
                'config' => $this->config($request),
                'release_percentage' => (int) $request->input('release_percentage', 100),
                'destinations' => $request->input('destinations'),
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
            'flow' => 'nullable|string|max:64',
            'days' => 'nullable|integer|min:1|max:730',
            'milestone' => 'nullable|string|max:64',
            'confirmed_by' => 'nullable|string|max:64',
            'release_percentage' => 'nullable|integer|min:1|max:100',
            'destinations' => 'nullable|array',
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

            if ($rule->condition_type === SettlementRule::CONDITION_MILESTONE) {
                foreach (['milestone', 'confirmed_by'] as $field) {
                    if ($request->has($field)) {
                        $rule->config = array_merge($rule->config ?? [], [$field => $request->input($field)]);
                    }
                }
            }

            // Switching a rule between plain and split would silently move it
            // between account-funded and pooled holds, so the shape is fixed
            // once created. The shares themselves can change.
            if ($request->has('destinations')) {
                if (!$rule->splitsRelease()) {
                    return $this->fail('Destinations can only be changed on a rule created with them. Create a new rule instead.', 422);
                }

                if ($error = SplitCalculator::validate($request->input('destinations'))) {
                    return $this->fail($error, 422);
                }

                $rule->destinations = $request->input('destinations');
            }

            if ($request->has('flow')) {
                $rule->flow = $request->input('flow');
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
        return match ($request->input('condition_type')) {
            SettlementRule::CONDITION_ELAPSED_DAYS => ['days' => (int) $request->input('days')],
            SettlementRule::CONDITION_MILESTONE => array_filter([
                'milestone' => $request->input('milestone'),
                'confirmed_by' => $request->input('confirmed_by'),
            ], fn ($v) => $v !== null),
            default => [],
        };
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
            'flow' => $rule->flow,
            'condition_type' => $rule->condition_type,
            'days' => $rule->condition_type === SettlementRule::CONDITION_ELAPSED_DAYS ? $rule->days() : null,
            'milestone' => $rule->milestone(),
            'confirmed_by' => $rule->confirmedBy(),
            'release_percentage' => (int) $rule->release_percentage,
            'destinations' => $rule->destinations,
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
        ];
    }
}
