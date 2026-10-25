<?php

namespace App\Http\Controllers\Concerns;

use App\Services\EscrowPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared guard for the public, unauthenticated webhook routes.
 *
 * These endpoints carry no API key — anyone can post to them — so the only
 * thing standing between a stranger and "this escrow is funded" is the
 * gateway's signature. VFD does not sign at all, hence the shared secret its
 * gateway checks instead.
 */
trait VerifiesGatewayWebhooks
{
    /**
     * @return bool True when the request genuinely came from the gateway.
     */
    protected function webhookIsAuthentic(Request $request, string $gateway): bool
    {
        // Symfony's HeaderBag already lower-cases its keys; flatten each value
        // to the first entry so gateways can read them directly.
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = is_array($values) ? ($values[0] ?? '') : $values;
        }

        try {
            $valid = app(EscrowPaymentService::class)
                ->getGateway($gateway)
                ->verifyWebhookSignature($request->getContent(), $headers);
        } catch (\Throwable $e) {
            Log::warning('Webhook signature check failed to run', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (!$valid) {
            Log::warning('Rejected unsigned webhook', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
            ]);
        }

        return $valid;
    }
}
