<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Transaction;
use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use App\PaymentGateways\VfdGateway;
use Illuminate\Support\Facades\Log;

class EscrowPaymentService
{
    protected array $gateways = [];

    public function registerGateway(string $key, PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$key] = $gateway;
    }

    public function hasGateway(string $key): bool
    {
        return isset($this->gateways[$key]);
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

    /**
     * Generate a gateway payment link for an existing escrow transaction.
     *
     * The transaction must already be persisted — this method never creates one.
     */
    public function createPaymentForTransaction(
        Transaction $transaction,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        ?ApiToken $token = null
    ): array {
        $gateway = $token
            ? $this->getGatewayForToken($token)
            : $this->getGateway();

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

        // Already settled — return the known state instead of re-applying it, so
        // that a webhook and a redirect-verify for the same payment are idempotent.
        if ($transaction->payment_status === 'Paid') {
            return [
                'status' => 'success',
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'reference' => $reference,
                'already_processed' => true,
                'gateway_response' => json_decode($transaction->gateway_response ?? '[]', true) ?: [],
            ];
        }

        $gatewayKey = $transaction->payment_gateway ?? config('escrow.default_gateway');
        $gateway = $this->getGateway($gatewayKey);

        $result = $gateway->verifyPayment($reference);

        if ($result['status'] === 'success' || $result['status'] === 'successful') {
            $expected = (float) $transaction->amount;
            $paid = (float) $result['amount'];

            // Never open escrow on an underpayment, and never on a currency swap.
            if (round($paid, 2) < round($expected, 2)) {
                $transaction->update([
                    'payment_status' => 'Underpaid',
                    'gateway_response' => json_encode($result['gateway_response']),
                ]);

                Log::warning('Escrow payment amount mismatch', [
                    'transcode' => $reference,
                    'expected' => $expected,
                    'paid' => $paid,
                ]);

                return array_merge($result, [
                    'status' => 'amount_mismatch',
                    'expected_amount' => $expected,
                ]);
            }

            if (!empty($result['currency']) && strcasecmp($result['currency'], (string) $transaction->currency) !== 0) {
                Log::warning('Escrow payment currency mismatch', [
                    'transcode' => $reference,
                    'expected' => $transaction->currency,
                    'paid' => $result['currency'],
                ]);

                return array_merge($result, [
                    'status' => 'currency_mismatch',
                    'expected_currency' => $transaction->currency,
                ]);
            }

            $transaction->update([
                'payment_status' => 'Paid',
                'trans_status' => 'Open',
                'payment_date' => now(),
                'amountpaid' => $paid,
                'gateway_response' => json_encode($result['gateway_response']),
            ]);
        }

        return $result;
    }

    /**
     * @param array{account_number: string, bank_code: string}|null $destination
     *        Where to send a VFD refund. Ignored by card gateways, which refund
     *        to the original payment instrument.
     */
    public function processEscrowRefund(Transaction $transaction, string $reason = '', ?array $destination = null): array
    {
        $gatewayKey = $transaction->payment_gateway ?? config('escrow.default_gateway');
        $gateway = $this->getGateway($gatewayKey);

        if ($gateway instanceof VfdGateway) {
            return $this->processVfdRefund($gateway, $transaction, $reason, $destination);
        }

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

    /**
     * VFD has no refund API — money goes back as an ordinary outbound transfer
     * from the pool account.
     *
     * The destination has to be explicit or recoverable from the inward credit
     * we recorded. VFD's credit notification carries the sender's account
     * number but not their bank, so a cross-bank payer cannot be reversed
     * automatically; callers must pass a destination in that case rather than
     * have us guess a bank code and pay a stranger.
     *
     * @param array{account_number: string, bank_code: string}|null $destination
     */
    protected function processVfdRefund(
        VfdGateway $gateway,
        Transaction $transaction,
        string $reason,
        ?array $destination,
    ): array {
        $destination = $destination ?? $this->recordedVfdSender($transaction);

        if (!$destination) {
            throw new \InvalidArgumentException(
                'A VFD refund needs an explicit destination (account_number and bank_code): '
                . 'the original credit did not record a reversible sender account.'
            );
        }

        $reference = 'RFD-' . $transaction->transcode . '-' . substr((string) time(), -6);

        $result = $gateway->initiateTransfer(
            $destination,
            (float) $transaction->amount,
            $reference,
            $reason !== '' ? $reason : "Refund for {$transaction->transcode}",
        );

        $transaction->update([
            'refunded' => 1,
            'trans_status' => 'Closed',
            'refund_date' => now(),
            'gateway_reference' => $result['transfer_code'] ?? $reference,
        ]);

        Log::info('VFD refund initiated', [
            'transaction' => $transaction->transcode,
            'reference' => $reference,
            'amount' => (float) $transaction->amount,
        ]);

        return [
            'status' => 'refund_initiated',
            'reference' => $result['transfer_code'] ?? $reference,
            'message' => 'Refund transfer initiated',
        ];
    }

    /**
     * Recover the payer's account from the stored credit notification. Only
     * usable when the payment came from inside VFD, since that is the one case
     * where we can infer the bank code.
     *
     * @return array{account_number: string, bank_code: string}|null
     */
    protected function recordedVfdSender(Transaction $transaction): ?array
    {
        $raw = $transaction->gateway_response;

        if (!$raw) {
            return null;
        }

        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (!is_array($data)) {
            return null;
        }

        $senderAccount = $data['senderAccountNo'] ?? null;
        $senderBank = $data['senderBank'] ?? $data['fromBank'] ?? null;

        if (!$senderAccount) {
            return null;
        }

        if (!$senderBank) {
            // No bank on the notification means we cannot safely route this.
            return null;
        }

        return [
            'account_number' => (string) $senderAccount,
            'bank_code' => (string) $senderBank,
        ];
    }
}
