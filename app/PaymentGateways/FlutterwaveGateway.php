<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Flutterwave\Service\Transactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwaveGateway implements PaymentGatewayInterface
{
    protected string $publicKey;
    protected string $secretKey;
    protected string $encryptionKey;
    protected string $baseUrl;

    public function __construct(array $config)
    {
        $this->publicKey = $config['public_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->encryptionKey = $config['encryption_key'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.flutterwave.com/v3';

        \Flutterwave\Flutterwave::setUp([
            'secret_key' => $this->secretKey,
            'public_key' => $this->publicKey,
            'encryption_key' => $this->encryptionKey,
            'environment' => app()->environment('production') ? 'production' : 'development',
        ]);
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
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/payments", [
                'tx_ref' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'redirect_url' => $callbackUrl,
                'customer' => [
                    'email' => $customerEmail,
                    'name' => $customerName,
                ],
                'meta' => $metadata,
                'customizations' => [
                    'title' => 'Pepper Escrow Payment',
                    'description' => 'Escrow transaction ' . $reference,
                ],
            ]);

        $result = $response->json();

        if (!$response->successful() || !($result['status'] === 'success')) {
            Log::error('Flutterwave payment link error', $result ?? []);
            throw new \Exception($result['message'] ?? 'Failed to create Flutterwave payment link');
        }

        return [
            'paymentUrl' => $result['data']['link'],
            'reference' => $reference,
        ];
    }

    public function verifyPayment(string $reference): array
    {
        try {
            $service = new Transactions();
            $result = $service->verifyWithTxref($reference);

            $data = $result->data ?? $result;

            return [
                'status' => ($data->status === 'successful') ? 'success' : ($data->status ?? 'failed'),
                'amount' => (float) ($data->amount ?? 0),
                'currency' => $data->currency ?? 'NGN',
                'reference' => $data->tx_ref ?? $reference,
                'gateway_response' => (array) $data,
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave verify error', ['reference' => $reference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        try {
            $service = new Transactions();
            $result = $service->refund($transactionReference);

            return [
                'status' => 'refund_initiated',
                'reference' => $result->data->id ?? $transactionReference,
                'message' => 'Refund processed successfully via Flutterwave',
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave refund error', ['reference' => $transactionReference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getName(): string
    {
        return 'Flutterwave';
    }

    public function getKey(): string
    {
        return 'flutterwave';
    }

    // ─── Virtual Account Number (VAF) ─────────────────────────────────

    public function createVirtualAccount(
        string $customerEmail,
        string $customerName,
        string $txRef,
        bool $isPermanent = false,
        ?string $phone = null,
    ): array {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/virtual-account-numbers", [
                'email' => $customerEmail,
                'is_permanent' => $isPermanent,
                'tx_ref' => $txRef,
                'phone_number' => $phone ?? '',
                'firstname' => explode(' ', $customerName)[0] ?? $customerName,
                'lastname' => explode(' ', $customerName)[1] ?? '',
                'narration' => 'Pepper Escrow - ' . $customerName,
            ]);

        $result = $response->json();

        if (!$response->successful() || !($result['status'] === 'success')) {
            Log::error('Flutterwave VAF creation error', $result ?? []);
            throw new \Exception($result['message'] ?? 'Failed to create Flutterwave virtual account');
        }

        return [
            'account_number' => $result['data']['account_number'],
            'account_name' => $result['data']['account_name'] ?? $customerName,
            'bank_name' => $result['data']['bank_name'],
            'provider_reference' => $result['data']['id'] ?? $result['data']['order_ref'],
        ];
    }

    public function deactivateVirtualAccount(string $providerReference): array
    {
        $response = Http::withToken($this->secretKey)
            ->delete("{$this->baseUrl}/virtual-account-numbers/{$providerReference}");

        $result = $response->json();

        return [
            'status' => $result['status'] === 'success' ? 'deactivated' : 'failed',
            'message' => $result['message'] ?? 'Deactivation processed',
        ];
    }

    public function fetchVirtualAccount(string $providerReference): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/virtual-account-numbers/{$providerReference}");

        $result = $response->json();

        if (!$response->successful()) {
            throw new \Exception($result['message'] ?? 'Failed to fetch Flutterwave virtual account');
        }

        return [
            'account_number' => $result['data']['account_number'],
            'account_name' => $result['data']['account_name'],
            'bank_name' => $result['data']['bank_name'],
            'status' => $result['data']['status'],
        ];
    }

    // ─── Transfer / Payout ────────────────────────────────────────────

    public function initiateTransfer(array $bankAccount, float $amount, string $reference, string $reason = ''): array
    {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/transfers", [
                'account_bank' => $bankAccount['bank_code'],
                'account_number' => $bankAccount['account_number'],
                'amount' => $amount,
                'narration' => $reason ?: 'Pepper Escrow Payout',
                'currency' => $bankAccount['currency'] ?? 'NGN',
                'reference' => $reference,
                'debit_currency' => $bankAccount['currency'] ?? 'NGN',
            ]);

        $result = $response->json();

        if (!$response->successful() || !($result['status'] === 'success')) {
            Log::error('Flutterwave transfer error', $result ?? []);
            throw new \Exception($result['message'] ?? 'Failed to initiate Flutterwave transfer');
        }

        return [
            'status' => $result['data']['status'],
            'transfer_id' => $result['data']['id'],
            'reference' => $result['data']['reference'],
            'amount' => (float) $result['data']['amount'],
        ];
    }

    public function verifyTransfer(string $transferId): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/transfers/{$transferId}");

        $result = $response->json();

        if (!$response->successful()) {
            throw new \Exception($result['message'] ?? 'Failed to verify Flutterwave transfer');
        }

        return [
            'status' => $result['data']['status'],
            'amount' => (float) $result['data']['amount'],
            'reference' => $result['data']['reference'],
            'transfer_id' => $result['data']['id'],
        ];
    }

    public function checkBalance(): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/balances");

        $result = $response->json();

        if (!$response->successful()) {
            throw new \Exception($result['message'] ?? 'Failed to check Flutterwave balance');
        }

        $currencies = [];
        foreach ($result['data'] as $balance) {
            $currencies[$balance['currency']] = [
                'balance' => (float) ($balance['available_balance'] ?? $balance['balance']),
                'limit' => 0,
            ];
        }

        return $currencies;
    }
}
