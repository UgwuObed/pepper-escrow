<?php

namespace App\PaymentGateways\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Generate a payment link for the given transaction.
     *
     * @return array{paymentUrl: string, reference: string}
     */
    public function createPaymentLink(
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        array $metadata = []
    ): array;

    /**
     * Verify a payment by transaction reference.
     *
     * @return array{status: string, amount: float, currency: string, reference: string, gateway_response: array}
     */
    public function verifyPayment(string $reference): array;

    /**
     * Process a refund for the given transaction.
     *
     * @return array{status: string, reference: string, message: string}
     */
    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array;

    /**
     * Verify that an inbound webhook was actually signed by the gateway.
     *
     * @param string $rawPayload The unparsed request body
     * @param array  $headers    Request headers, lower-cased keys
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * Get the display name of the gateway.
     */
    public function getName(): string;

    /**
     * Get the gateway identifier key.
     */
    public function getKey(): string;
}
