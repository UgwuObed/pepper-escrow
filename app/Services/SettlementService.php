<?php

namespace App\Services;

use App\Models\ClientConfig;
use App\Models\Merchant;
use App\Models\MerchantBankAccount;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Models\Transaction;
use App\Services\NotificationService;
use App\PaymentGateways\FlutterwaveGateway;
use App\PaymentGateways\PaystackGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SettlementService
{
    protected EscrowPaymentService $paymentService;

    public function __construct(EscrowPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function getSettlableTransactions(Merchant $merchant, ?string $currency = 'NGN'): array
    {
        return Transaction::where('appid', $merchant->id)
            ->where('trans_status', 'Closed')
            ->where('payment_status', 'Paid')
            ->whereNull('settled_at')
            ->when($currency, fn($q, $c) => $q->where('currency', $c))
            ->orderBy('payment_date', 'asc')
            ->get()
            ->all();
    }

    public function createSettlementBatch(
        Merchant $merchant,
        array $transactions,
        ?string $gateway = null,
        ?string $currency = 'NGN',
    ): Settlement {
        $gateway = $gateway ?? config('escrow.default_gateway', 'paystack');

        $totalAmount = 0;
        $totalCommission = 0;
        $items = [];

        foreach ($transactions as $txn) {
            $commission = (float) ($txn->commission_amount ?? 0);
            $netAmount = (float) ($txn->net_amount ?? $txn->amount) - $commission;
            $totalAmount += (float) $txn->amount;
            $totalCommission += $commission;
            $items[] = [
                'transaction_id' => $txn->id,
                'transaction_amount' => $txn->amount,
                'commission_amount' => $commission,
                'net_amount' => $netAmount,
            ];
        }

        $netAmount = $totalAmount - $totalCommission;

        $settlement = DB::transaction(function () use ($merchant, $items, $totalAmount, $totalCommission, $netAmount, $gateway, $currency) {
            $batchNumber = 'STL-' . strtoupper(Str::random(12));

            $settlement = Settlement::create([
                'merchant_id' => $merchant->id,
                'batch_number' => $batchNumber,
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'total_commission' => $totalCommission,
                'net_amount' => $netAmount,
                'item_count' => count($items),
                'currency' => $currency,
                'payment_gateway' => $gateway,
            ]);

            foreach ($items as $item) {
                SettlementItem::create(array_merge($item, [
                    'settlement_id' => $settlement->id,
                    'status' => 'included',
                ]));

                Transaction::where('id', $item['transaction_id'])->update(['settled_at' => now()]);
            }

            return $settlement;
        });

        return $settlement;
    }

    public function processSettlement(Settlement $settlement): Settlement
    {
        if ($settlement->status !== 'pending') {
            throw new \RuntimeException("Settlement {$settlement->batch_number} is already {$settlement->status}.");
        }

        $merchant = $settlement->merchant;
        $bankAccount = MerchantBankAccount::where('merchant_id', $merchant->id)
            ->where('status', true)
            ->where('is_default', true)
            ->first();

        if (!$bankAccount) {
            $settlement->markFailed('No active default bank account found.');
            return $settlement;
        }

        $settlement->markProcessing();

        try {
            $gateway = $this->paymentService->getGateway($settlement->payment_gateway);
            $reference = $settlement->batch_number;

            $result = match ($settlement->payment_gateway) {
                'paystack' => $this->processPaystackPayout($gateway, $bankAccount, $settlement, $reference),
                'flutterwave' => $this->processFlutterwavePayout($gateway, $bankAccount, $settlement, $reference),
                default => throw new \InvalidArgumentException("Unsupported gateway: {$settlement->payment_gateway}"),
            };

            $settlement->markCompleted($result['reference'] ?? null);

            foreach ($settlement->items as $item) {
                $item->markPaid();
            }

            // Notify merchant of completed settlement
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->notifySettlementCompleted($merchant, [
                    'batch_number' => $settlement->batch_number,
                    'net_amount' => $settlement->net_amount,
                    'total_amount' => $settlement->total_amount,
                    'item_count' => $settlement->item_count,
                    'reference_type' => 'settlement',
                    'reference_id' => $settlement->id,
                ]);
            } catch (\Exception $e) {
                // Non-blocking
            }
        } catch (\Exception $e) {
            Log::error('Settlement processing failed', [
                'batch' => $settlement->batch_number,
                'error' => $e->getMessage(),
            ]);

            $settlement->markFailed($e->getMessage());

            foreach ($settlement->items as $item) {
                if ($item->status === 'included') {
                    $item->markFailed($e->getMessage());
                }
            }
        }

        return $settlement->fresh();
    }

    public function processAutoSettlements(): array
    {
        $processed = [];
        $merchants = ClientConfig::whereIn('settlement_schedule', ['daily', 'weekly', 'monthly'])
            ->where('min_settlement_amount', '>', 0)
            ->get();

        foreach ($merchants as $config) {
            $merchant = $config->merchant;
            if (!$merchant || !$this->isSettlementDue($config)) {
                continue;
            }

            $transactions = $this->getSettlableTransactions($merchant);

            if (empty($transactions)) {
                continue;
            }

            $totalRaw = array_sum(array_map(fn($t) => (float) $t->amount, $transactions));
            if ($totalRaw < (float) $config->min_settlement_amount) {
                continue;
            }

            try {
                $settlement = $this->createSettlementBatch($merchant, $transactions);
                $this->processSettlement($settlement);
                $processed[] = $settlement;
            } catch (\Exception $e) {
                Log::error('Auto settlement error', ['merchant' => $merchant->id, 'error' => $e->getMessage()]);
            }
        }

        return $processed;
    }

    public function handlePaystackTransferWebhook(array $payload): ?Settlement
    {
        $event = $payload['event'] ?? '';
        if (!str_starts_with($event, 'transfer.')) {
            return null;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return null;
        }

        $settlement = Settlement::where('gateway_transfer_ref', $reference)
            ->orWhere('batch_number', $reference)
            ->first();

        if (!$settlement) {
            return null;
        }

        $status = $data['status'] ?? 'unknown';

        if ($status === 'success') {
            $settlement->markCompleted();
            foreach ($settlement->items as $item) {
                $item->markPaid();
            }
        } elseif (in_array($status, ['failed', 'reversed'])) {
            $settlement->markFailed("Gateway reported: {$status}");
            foreach ($settlement->items as $item) {
                if ($item->status === 'paid') {
                    $item->markFailed("Gateway: {$status}");
                }
            }
        }

        return $settlement->fresh();
    }

    public function handleFlutterwaveTransferWebhook(array $payload): ?Settlement
    {
        $event = $payload['event'] ?? '';
        if ($event !== 'transfer.completed') {
            return null;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return null;
        }

        $settlement = Settlement::where('gateway_transfer_ref', $reference)
            ->orWhere('batch_number', $reference)
            ->first();

        if (!$settlement) {
            return null;
        }

        $status = $data['status'] ?? 'unknown';

        if ($status === 'SUCCESSFUL') {
            $settlement->markCompleted();
            foreach ($settlement->items as $item) {
                $item->markPaid();
            }
        } elseif (in_array($status, ['FAILED', 'REVERSED'])) {
            $settlement->markFailed("Gateway reported: {$status}");
            foreach ($settlement->items as $item) {
                if ($item->status === 'paid') {
                    $item->markFailed("Gateway: {$status}");
                }
            }
        }

        return $settlement->fresh();
    }

    protected function processPaystackPayout(PaystackGateway $gateway, MerchantBankAccount $bank, Settlement $settlement, string $reference): array
    {
        $recipient = $gateway->createTransferRecipient([
            'account_name' => $bank->account_name,
            'account_number' => $bank->account_number,
            'bank_code' => $bank->bank_code,
            'currency' => $bank->currency ?? 'NGN',
        ]);

        $result = $gateway->initiateTransfer(
            $recipient['recipient_code'],
            (float) $settlement->net_amount,
            $reference,
            "Settlement batch {$settlement->batch_number}",
        );

        return [
            'status' => $result['status'],
            'reference' => $result['transfer_code'] ?? $result['reference'],
        ];
    }

    protected function processFlutterwavePayout(FlutterwaveGateway $gateway, MerchantBankAccount $bank, Settlement $settlement, string $reference): array
    {
        $result = $gateway->initiateTransfer(
            [
                'bank_code' => $bank->bank_code,
                'account_number' => $bank->account_number,
                'currency' => $bank->currency ?? 'NGN',
            ],
            (float) $settlement->net_amount,
            $reference,
            "Settlement batch {$settlement->batch_number}",
        );

        return [
            'status' => $result['status'],
            'reference' => (string) ($result['transfer_id'] ?? $result['reference']),
        ];
    }

    protected function isSettlementDue(ClientConfig $config): bool
    {
        $schedule = $config->settlement_schedule;

        if ($schedule === 'daily') {
            return true;
        }

        $day = $config->settlement_day ?? 1;
        $now = now();

        return match ($schedule) {
            'weekly' => (int) $now->format('N') === (int) $day,
            'monthly' => (int) $now->format('j') === (int) $day,
            default => false,
        };
    }
}
