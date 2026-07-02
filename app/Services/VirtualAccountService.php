<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use App\PaymentGateways\FlutterwaveGateway;
use App\PaymentGateways\PaystackGateway;
use Illuminate\Support\Facades\Log;

class VirtualAccountService
{
    protected EscrowPaymentService $paymentService;

    public function __construct(EscrowPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function assignToTransaction(
        Transaction $transaction,
        Merchant $merchant,
        ?string $gateway = null,
    ): VirtualAccount {
        $gateway = $gateway ?? $transaction->payment_gateway ?? config('escrow.default_gateway');
        $customerEmail = $transaction->customer_email;
        $customerName = $transaction->customer_email;

        $existing = VirtualAccount::byCustomer($customerEmail)
            ->byGateway($gateway)
            ->active()
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($existing) {
            $existing->update([
                'transaction_id' => $transaction->id,
                'status' => 'assigned',
            ]);

            $transaction->update([
                'payment_gateway' => $gateway,
                'gateway_reference' => $existing->account_number,
            ]);

            return $existing->fresh();
        }

        $gwInstance = $this->paymentService->getGateway($gateway);

        $vaData = match ($gateway) {
            'paystack' => $this->createPaystackDVA($gwInstance, $customerEmail, $customerName),
            'flutterwave' => $this->createFlutterwaveVAF($gwInstance, $customerEmail, $customerName, $transaction->transcode),
            default => throw new \InvalidArgumentException("Gateway '{$gateway}' does not support virtual accounts"),
        };

        $virtualAccount = VirtualAccount::create([
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'gateway' => $gateway,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'account_number' => $vaData['account_number'],
            'account_name' => $vaData['account_name'],
            'bank_name' => $vaData['bank_name'],
            'provider_reference' => $vaData['provider_reference'],
            'status' => 'assigned',
            'is_permanent' => true,
        ]);

        $transaction->update([
            'payment_gateway' => $gateway,
            'gateway_reference' => $vaData['account_number'],
        ]);

        return $virtualAccount;
    }

    public function createNewForTransaction(
        Transaction $transaction,
        Merchant $merchant,
        ?string $gateway = null,
    ): VirtualAccount {
        $gateway = $gateway ?? $transaction->payment_gateway ?? config('escrow.default_gateway');
        $customerEmail = $transaction->customer_email;
        $customerName = $transaction->customer_email;

        $gwInstance = $this->paymentService->getGateway($gateway);

        $vaData = match ($gateway) {
            'paystack' => $this->createPaystackDVA($gwInstance, $customerEmail, $customerName),
            'flutterwave' => $this->createFlutterwaveVAF($gwInstance, $customerEmail, $customerName, $transaction->transcode),
            default => throw new \InvalidArgumentException("Gateway '{$gateway}' does not support virtual accounts"),
        };

        $virtualAccount = VirtualAccount::create([
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'gateway' => $gateway,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'account_number' => $vaData['account_number'],
            'account_name' => $vaData['account_name'],
            'bank_name' => $vaData['bank_name'],
            'provider_reference' => $vaData['provider_reference'],
            'status' => 'assigned',
            'is_permanent' => false,
            'expires_at' => now()->addDays(7),
        ]);

        $transaction->update([
            'payment_gateway' => $gateway,
            'gateway_reference' => $vaData['account_number'],
        ]);

        return $virtualAccount;
    }

    public function handlePaystackCredit(array $payload): ?Transaction
    {
        $event = $payload['event'] ?? '';
        if ($event !== 'charge.success') {
            return null;
        }

        $data = $payload['data'] ?? [];
        $customerEmail = $data['customer']['email'] ?? null;
        $accountNumber = $data['authorization']['account_number'] ?? null;

        if (!$customerEmail || !$accountNumber) {
            Log::warning('Paystack DVA webhook missing customer email or account number');
            return null;
        }

        $va = VirtualAccount::byGateway('paystack')
            ->where('account_number', $accountNumber)
            ->where('customer_email', $customerEmail)
            ->active()
            ->first();

        if (!$va || !$va->transaction_id) {
            Log::info('Paystack DVA webhook: no matching assigned VA found', [
                'account' => $accountNumber,
                'email' => $customerEmail,
            ]);
            return null;
        }

        $transaction = Transaction::find($va->transaction_id);
        if (!$transaction) {
            return null;
        }

        $amount = $data['amount'] / 100;

        $transaction->update([
            'payment_status' => 'Paid',
            'trans_status' => 'Open',
            'payment_date' => now(),
            'payment_gateway' => 'paystack',
            'gateway_reference' => $data['reference'] ?? $transaction->gateway_reference,
            'amount' => $amount,
            'gateway_response' => json_encode($data),
        ]);

        return $transaction;
    }

    public function handleFlutterwaveCredit(array $payload): ?Transaction
    {
        $event = $payload['event'] ?? '';
        if ($event !== 'charge.completed' && $event !== 'transfer.completed') {
            return null;
        }

        $data = $payload['data'] ?? [];
        $customerEmail = $data['customer']['email'] ?? $data['customer_email'] ?? null;
        $accountNumber = $data['account_number'] ?? null;
        $txRef = $data['tx_ref'] ?? null;

        if (!$accountNumber && !$txRef) {
            Log::warning('Flutterwave VAF webhook missing account number or tx_ref');
            return null;
        }

        $query = VirtualAccount::byGateway('flutterwave')->active();

        if ($accountNumber) {
            $query->where('account_number', $accountNumber);
        } elseif ($txRef) {
            $query->whereHas('transaction', fn($q) => $q->where('transcode', $txRef));
        }

        $va = $query->first();

        if (!$va || !$va->transaction_id) {
            Log::info('Flutterwave VAF webhook: no matching assigned VA found', [
                'account' => $accountNumber,
                'tx_ref' => $txRef,
            ]);
            return null;
        }

        $transaction = Transaction::find($va->transaction_id);
        if (!$transaction) {
            return null;
        }

        $amount = $data['amount'] ?? $data['charged_amount'] ?? $transaction->amount;

        $transaction->update([
            'payment_status' => 'Paid',
            'trans_status' => 'Open',
            'payment_date' => now(),
            'payment_gateway' => 'flutterwave',
            'gateway_reference' => $data['id'] ?? $data['transaction_id'] ?? $transaction->gateway_reference,
            'amount' => $amount,
            'gateway_response' => json_encode($data),
        ]);

        return $transaction;
    }

    public function deactivate(VirtualAccount $va): void
    {
        $gateway = $va->gateway;
        $gwInstance = $this->paymentService->getGateway($gateway);

        match ($gateway) {
            'paystack' => $gwInstance->deactivateDedicatedAccount($va->provider_reference),
            'flutterwave' => $gwInstance->deactivateVirtualAccount($va->provider_reference),
            default => throw new \InvalidArgumentException("Unsupported gateway: {$gateway}"),
        };

        $va->update(['status' => 'closed']);
    }

    public function getActiveForCustomer(int $merchantId, string $customerEmail, ?string $gateway = null): ?VirtualAccount
    {
        $query = VirtualAccount::where('merchant_id', $merchantId)
            ->where('customer_email', $customerEmail)
            ->active();

        if ($gateway) {
            $query->where('gateway', $gateway);
        }

        return $query->first();
    }

    protected function createPaystackDVA(PaymentGatewayInterface $gw, string $email, string $name): array
    {
        if (!$gw instanceof PaystackGateway) {
            throw new \RuntimeException('Gateway is not Paystack');
        }
        return $gw->createDedicatedAccount($email, $name);
    }

    protected function createFlutterwaveVAF(PaymentGatewayInterface $gw, string $email, string $name, string $txRef): array
    {
        if (!$gw instanceof FlutterwaveGateway) {
            throw new \RuntimeException('Gateway is not Flutterwave');
        }
        return $gw->createVirtualAccount($email, $name, $txRef, isPermanent: true);
    }
}
