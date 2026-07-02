<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Transaction;
use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;

class EscrowPaymentService
{
    protected array $gateways = [];

    public function registerGateway(string $key, PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$key] = $gateway;
    }

    public function getGateway(?string $key = null): PaymentGatewayInterface
    {
        $key = $key ?? config('escrow.default_gateway', 'paystack');

        if (!isset($this->gateways[$key])) {
            throw new \RuntimeException("Payment gateway '{$key}' is not registered.");
        }

        return $this->gateways[$key];
    }

    public function getGatewayForToken(ApiToken $token): PaymentGatewayInterface
    {
        $gatewayKey = $token->payment_gateway ?? config('escrow.default_gateway', 'paystack');
        $gateway = $this->getGateway($gatewayKey);

        if ($token->gateway_config) {
            $reflection = new \ReflectionClass($gateway);
            $instance = $reflection->newInstance($token->gateway_config);
            return $instance;
        }

        return $gateway;
    }

    public function getAvailableGateways(): array
    {
        $list = [];
        foreach ($this->gateways as $key => $gateway) {
            $list[$key] = $gateway->getName();
        }
        return $list;
    }

    public function createTransactionWithPayment(
        array $transactionData,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        ?ApiToken $token = null
    ): array {
        $gateway = $token
            ? $this->getGatewayForToken($token)
            : $this->getGateway();

        $transaction = Transaction::create($transactionData);

        $reference = $transaction->transcode;

        try {
            $paymentLink = $gateway->createPaymentLink(
                amount: (float) $transaction->amount,
                currency: $transaction->currency ?? 'NGN',
                reference: $reference,
                customerEmail: $customerEmail,
                customerName: $customerName,
                callbackUrl: $callbackUrl,
                metadata: [
                    'transaction_id' => $transaction->id,
                    'appid' => $transaction->appid,
                ]
            );

            $transaction->update([
                'payment_gateway' => $gateway->getKey(),
                'gateway_reference' => $paymentLink['reference'],
            ]);

            return [
                'transaction' => $transaction,
                'payment_url' => $paymentLink['paymentUrl'],
                'reference' => $paymentLink['reference'],
                'gateway' => $gateway->getKey(),
            ];
        } catch (\Exception $e) {
            Log::error('Escrow payment creation failed', [
                'transcode' => $reference,
                'error' => $e->getMessage(),
            ]);

            $transaction->update(['trans_status' => 'PaymentFailed']);

            throw $e;
        }
    }

    public function verifyTransactionPayment(string $reference, ?ApiToken $token = null): array
    {
        $transaction = Transaction::where('transcode', $reference)->firstOrFail();

        $gatewayKey = $transaction->payment_gateway ?? config('escrow.default_gateway');
        $gateway = $this->getGateway($gatewayKey);

        $result = $gateway->verifyPayment($reference);

        if ($result['status'] === 'success') {
            $transaction->update([
                'payment_status' => 'Paid',
                'trans_status' => 'Open',
                'payment_date' => now(),
                'gateway_response' => json_encode($result['gateway_response']),
            ]);
        }

        return $result;
    }

    public function processEscrowRefund(Transaction $transaction, string $reason = ''): array
    {
        $gatewayKey = $transaction->payment_gateway ?? config('escrow.default_gateway');
        $gateway = $this->getGateway($gatewayKey);

        $gatewayRef = $transaction->gateway_reference ?? $transaction->transcode;

        $result = $gateway->processRefund($gatewayRef, (float) $transaction->amount, $reason);

        if ($result['status'] === 'succeeded' || $result['status'] === 'refund_initiated') {
            $transaction->update([
                'refunded' => 1,
                'trans_status' => 'Closed',
                'refund_date' => now(),
            ]);
        }

        return $result;
    }
}
