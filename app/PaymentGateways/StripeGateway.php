<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Refund;

class StripeGateway implements PaymentGatewayInterface
{
    protected string $secretKey;
    protected string $publicKey;

    public function __construct(array $config)
    {
        $this->secretKey = $config['secret_key'] ?? config('gateways.stripe.secret_key');
        $this->publicKey = $config['public_key'] ?? config('gateways.stripe.public_key');

        Stripe::setApiKey($this->secretKey);
    }

    public function createPaymentLink(
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        array $metadata = []
    ): array {
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => 'Escrow Payment',
                        'description' => "Transaction reference: {$reference}",
                    ],
                    'unit_amount' => (int)($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $callbackUrl . '?reference=' . $reference . '&status=success',
            'cancel_url' => $callbackUrl . '?reference=' . $reference . '&status=cancelled',
            'client_reference_id' => $reference,
            'customer_email' => $customerEmail,
            'metadata' => array_merge($metadata, [
                'transaction_reference' => $reference,
            ]),
        ]);

        return [
            'paymentUrl' => $session->url,
            'reference' => $reference,
        ];
    }

    public function verifyPayment(string $reference): array
    {
        try {
            $session = Session::retrieve($reference);

            return [
                'status' => $session->payment_status === 'paid' ? 'success' : $session->payment_status,
                'amount' => $session->amount_total / 100,
                'currency' => strtoupper($session->currency),
                'reference' => $session->client_reference_id ?? $reference,
                'gateway_response' => $session->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error('Stripe verify error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        $refund = Refund::create([
            'payment_intent' => $transactionReference,
            'amount' => (int)($amount * 100),
            'reason' => $reason ? 'requested_by_customer' : null,
        ]);

        return [
            'status' => $refund->status,
            'reference' => $refund->id,
            'message' => 'Refund ' . $refund->status,
        ];
    }

    public function getName(): string
    {
        return 'Stripe';
    }

    public function getKey(): string
    {
        return 'stripe';
    }
}
