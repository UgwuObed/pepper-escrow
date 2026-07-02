<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeerBitGateway implements PaymentGatewayInterface
{
    protected string $publicKey;
    protected string $privateKey;

    public function __construct(array $config)
    {
        $this->publicKey = $config['public_key'] ?? config('gateways.seerbit.public_key');
        $this->privateKey = $config['private_key'] ?? config('gateways.seerbit.private_key');
    }

    protected function getEncryptedKey(): string
    {
        $keyString = $this->privateKey . '.' . $this->publicKey;

        $response = Http::post('https://seerbitapi.com/api/v2/encrypt/keys', [
            'key' => $keyString,
        ]);

        $result = $response->json();

        if (!($result['data']['code'] ?? '') === '00') {
            throw new \Exception('Failed to encrypt SeerBit key');
        }

        return $result['data']['EncryptedSecKey']['encryptedKey'];
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
        $stringToHash = "amount={$amount}&callbackUrl={$callbackUrl}&country=NG&currency={$currency}&email={$customerEmail}&paymentReference={$reference}&productDescription=Escrow Payment&productId={$reference}&publicKey={$this->publicKey}";
        $hash = hash('sha256', $stringToHash . $this->privateKey);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->publicKey}",
            'Content-Type' => 'application/json',
        ])->post('https://seerbitapi.com/api/v2/payments', [
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'country' => 'NG',
            'currency' => $currency,
            'email' => $customerEmail,
            'paymentReference' => $reference,
            'productDescription' => 'Escrow Payment',
            'productId' => $reference,
            'publicKey' => $this->publicKey,
            'hash' => $hash,
        ]);

        $result = $response->json();

        if (!$response->successful() || !($result['status'] ?? false)) {
            Log::error('SeerBit payment link error', $result ?? []);
            throw new \Exception($result['message'] ?? 'Failed to create SeerBit payment link');
        }

        return [
            'paymentUrl' => $result['data']['payment']['redirectUrl'] ?? $result['data']['payment']['url'],
            'reference' => $reference,
        ];
    }

    public function verifyPayment(string $reference): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->publicKey}",
        ])->get("https://seerbitapi.com/api/v2/payments/query/{$reference}");

        $result = $response->json();

        if (!$response->successful()) {
            Log::error('SeerBit verify error', $result ?? []);
            throw new \Exception($result['message'] ?? 'Failed to verify SeerBit payment');
        }

        $data = $result['data']['payments'][0] ?? $result['data'];

        return [
            'status' => $data['status'] ?? $data['processorMessage'] ?? 'unknown',
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'reference' => $data['paymentReference'],
            'gateway_response' => $data,
        ];
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        return [
            'status' => 'refund_initiated',
            'reference' => $transactionReference,
            'message' => 'SeerBit refund initiated. Please process manually via SeerBit dashboard.',
        ];
    }

    public function getName(): string
    {
        return 'SeerBit';
    }

    public function getKey(): string
    {
        return 'seerbit';
    }
}
