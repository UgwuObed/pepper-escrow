<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;

class PaystackGateway implements PaymentGatewayInterface
{
    protected \Yabacon\Paystack $paystack;
    protected string $publicKey;

    public function __construct(array $config)
    {
        $this->publicKey = $config['public_key'] ?? config('gateways.paystack.public_key');
        $this->paystack = new \Yabacon\Paystack($config['secret_key'] ?? config('gateways.paystack.secret_key'));
        $this->paystack->useGuzzle();
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
        try {
            $result = $this->paystack->transaction->initialize([
                'amount' => intval($amount * 100),
                'reference' => $reference,
                'email' => $customerEmail,
                'currency' => $currency,
                'callback_url' => $callbackUrl,
                'metadata' => array_merge($metadata, [
                    'customer_name' => $customerName,
                ]),
            ]);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to create Paystack payment link');
            }

            return [
                'paymentUrl' => $result->data->authorization_url,
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack payment link error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function verifyPayment(string $reference): array
    {
        try {
            $result = $this->paystack->transaction->verify(['reference' => $reference]);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to verify Paystack payment');
            }

            return [
                'status' => $result->data->status,
                'amount' => $result->data->amount / 100,
                'currency' => $result->data->currency,
                'reference' => $result->data->reference,
                'gateway_response' => (array) $result->data,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack verify error', ['reference' => $reference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        try {
            $result = $this->paystack->refund->run([
                'transaction' => $transactionReference,
                'amount' => intval($amount * 100),
                'reason' => $reason,
            ]);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to process Paystack refund');
            }

            return [
                'status' => $result->data->status,
                'reference' => $result->data->id,
                'message' => 'Refund processed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack refund error', ['reference' => $transactionReference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getName(): string
    {
        return 'Paystack';
    }

    public function getKey(): string
    {
        return 'paystack';
    }

    // ─── Dedicated Virtual Account (DVA) ──────────────────────────────

    public function createDedicatedAccount(
        string $customerEmail,
        string $customerName,
        ?string $phone = null,
    ): array {
        try {
            $payload = [
                'customer' => $customerEmail,
                'first_name' => explode(' ', $customerName)[0] ?? $customerName,
                'last_name' => explode(' ', $customerName)[1] ?? '',
                'phone' => $phone ?? '',
                'preferred_bank' => 'wema-bank',
            ];

            $result = $this->paystack->dedicatedAccount->create($payload);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to create Paystack DVA');
            }

            return [
                'account_number' => $result->data->account_number,
                'account_name' => $result->data->account_name,
                'bank_name' => $result->data->bank->name,
                'provider_reference' => (string) $result->data->id,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack DVA creation error', ['email' => $customerEmail, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deactivateDedicatedAccount(string $providerReference): array
    {
        try {
            $result = $this->paystack->dedicatedAccount->deactivate($providerReference);

            return [
                'status' => $result->status ? 'deactivated' : 'failed',
                'message' => $result->message ?? 'Deactivation processed',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack DVA deactivation error', ['ref' => $providerReference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function fetchDedicatedAccount(string $providerReference): array
    {
        try {
            $result = $this->paystack->dedicatedAccount->fetch($providerReference);

            return [
                'account_number' => $result->data->account_number,
                'account_name' => $result->data->account_name,
                'bank_name' => $result->data->bank->name,
                'status' => $result->data->status,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack DVA fetch error', ['ref' => $providerReference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    // ─── Transfer / Payout ────────────────────────────────────────────

    public function createTransferRecipient(array $bankAccount): array
    {
        try {
            $result = $this->paystack->transferrecipient->create([
                'type' => 'nuban',
                'name' => $bankAccount['account_name'],
                'account_number' => $bankAccount['account_number'],
                'bank_code' => $bankAccount['bank_code'],
                'currency' => $bankAccount['currency'] ?? 'NGN',
            ]);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to create transfer recipient');
            }

            return [
                'recipient_code' => $result->data->recipient_code,
                'recipient_id' => (string) $result->data->id,
                'active' => $result->data->active,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack create recipient error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function initiateTransfer(string $recipientCode, float $amount, string $reference, string $reason = ''): array
    {
        try {
            $result = $this->paystack->transfer->run([
                'source' => 'balance',
                'amount' => intval($amount * 100),
                'reference' => $reference,
                'recipient' => $recipientCode,
                'reason' => $reason,
            ]);

            if (!$result->status) {
                throw new \Exception($result->message ?? 'Failed to initiate transfer');
            }

            return [
                'status' => $result->data->status,
                'transfer_code' => $result->data->transfer_code,
                'reference' => $result->data->reference,
                'amount' => $result->data->amount / 100,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack transfer error', ['reference' => $reference, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function verifyTransfer(string $transferCode): array
    {
        try {
            $result = $this->paystack->transfer->verify($transferCode);

            return [
                'status' => $result->data->status,
                'amount' => $result->data->amount / 100,
                'reference' => $result->data->reference,
                'transfer_code' => $result->data->transfer_code,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack transfer verify error', ['code' => $transferCode, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function checkBalance(): array
    {
        try {
            $result = $this->paystack->balance->check();

            $currencies = [];
            foreach ($result->data as $balance) {
                $currencies[$balance->currency] = [
                    'balance' => $balance->balance / 100,
                    'limit' => $balance->limit / 100,
                ];
            }

            return $currencies;
        } catch (\Exception $e) {
            Log::error('Paystack balance check error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
