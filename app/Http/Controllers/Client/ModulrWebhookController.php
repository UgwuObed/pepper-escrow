<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\VerifiesGatewayWebhooks;
use App\Http\Controllers\Controller;
use App\Models\SettlementHold;
use App\PaymentGateways\ModulrGateway;
use App\Services\SettlementRulesEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Modulr notifications. Public, so the signature is the first check; the
 * engine then confirms any pay-in against the account balance before a hold
 * is marked paid.
 *
 * Modulr retries anything that is not a 2xx, up to five times over about
 * thirteen hours. A notification we cannot act on yet (a balance that does
 * not confirm it) returns 500 so it comes back; one that is not ours to act
 * on returns 200 so it stops.
 */
class ModulrWebhookController extends Controller
{
    use VerifiesGatewayWebhooks;

    public function __invoke(Request $request, SettlementRulesEngine $engine): JsonResponse
    {
        if (!$this->webhookIsAuthentic($request, 'modulr')) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        $payin = ModulrGateway::parsePayin($request->all());

        if (!$payin) {
            return response()->json(['status' => 'ok', 'message' => 'Ignored: not a pay-in.']);
        }

        $hold = SettlementHold::where('funding', SettlementHold::FUNDING_BANK_TRANSFER)
            ->where('bank_account_id', $payin['account_id'])
            ->first();

        if (!$hold) {
            Log::warning('Modulr pay-in for an account with no hold', [
                'account' => $payin['account_id'],
                'payment_id' => $payin['payment_id'],
            ]);

            return response()->json(['status' => 'ok', 'message' => 'Ignored: no hold for this account.']);
        }

        try {
            $hold = $engine->bankPaymentReceived($hold, $payin);
        } catch (\Throwable $e) {
            Log::error('Modulr pay-in could not be applied', [
                'hold' => $hold->reference,
                'payment_id' => $payin['payment_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Not applied yet; retry.'], 500);
        }

        return response()->json(['status' => 'success', 'hold' => $hold->reference, 'hold_status' => $hold->status]);
    }
}
