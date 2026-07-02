<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\RewardBalance;
use App\Models\RewardProgram;
use App\Models\RewardTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RewardService
{
    public function earnOnTransaction(Transaction $txn, Merchant $merchant): ?RewardTransaction
    {
        $programs = RewardProgram::byMerchant($merchant->id)
            ->active()
            ->get();

        foreach ($programs as $program) {
            if (!$program->appliesToTransaction($txn)) {
                continue;
            }

            $rewardAmount = $program->calculateReward((float) $txn->amount);
            if ($rewardAmount <= 0) {
                continue;
            }

            return $this->award($merchant, $txn->customer_email, $program->reward_type, $rewardAmount, $txn);
        }

        return null;
    }

    public function award(Merchant $merchant, string $customerEmail, string $rewardType, float $amount, ?Transaction $txn = null, ?string $description = null): RewardTransaction
    {
        return DB::transaction(function () use ($merchant, $customerEmail, $rewardType, $amount, $txn, $description) {
            $balance = RewardBalance::firstOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'customer_email' => $customerEmail,
                    'reward_type' => $rewardType,
                ],
                [
                    'balance' => 0,
                    'lifetime_earned' => 0,
                    'lifetime_redeemed' => 0,
                ]
            );

            $balance->increment('balance', $amount);
            $balance->increment('lifetime_earned', $amount);

            return RewardTransaction::create([
                'merchant_id' => $merchant->id,
                'reward_balance_id' => $balance->id,
                'transaction_id' => $txn?->id,
                'customer_email' => $customerEmail,
                'type' => 'earned',
                'amount' => $amount,
                'description' => $description ?? ($txn ? "Reward for transaction {$txn->transcode}" : 'Manual award'),
                'reference_type' => $txn ? 'transaction' : null,
                'reference_id' => $txn?->id,
            ]);
        });
    }

    public function redeem(Merchant $merchant, string $customerEmail, string $rewardType, float $amount, ?string $description = null): RewardTransaction
    {
        return DB::transaction(function () use ($merchant, $customerEmail, $rewardType, $amount, $description) {
            $balance = RewardBalance::byMerchant($merchant->id)
                ->byCustomer($customerEmail)
                ->where('reward_type', $rewardType)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $balance->balance < $amount) {
                throw new \RuntimeException("Insufficient {$rewardType} balance. Available: {$balance->balance}, requested: {$amount}.");
            }

            $balance->decrement('balance', $amount);
            $balance->increment('lifetime_redeemed', $amount);

            return RewardTransaction::create([
                'merchant_id' => $merchant->id,
                'reward_balance_id' => $balance->id,
                'customer_email' => $customerEmail,
                'type' => 'redeemed',
                'amount' => $amount,
                'description' => $description ?? 'Reward redemption',
                'reference_type' => 'redemption',
            ]);
        });
    }

    public function getBalance(Merchant $merchant, string $customerEmail, string $rewardType): float
    {
        $balance = RewardBalance::byMerchant($merchant->id)
            ->byCustomer($customerEmail)
            ->where('reward_type', $rewardType)
            ->first();

        return (float) ($balance?->balance ?? 0);
    }

    public function getBalances(Merchant $merchant, string $customerEmail): array
    {
        return RewardBalance::byMerchant($merchant->id)
            ->byCustomer($customerEmail)
            ->get()
            ->keyBy('reward_type')
            ->map(fn($b) => [
                'balance' => (float) $b->balance,
                'lifetime_earned' => (float) $b->lifetime_earned,
                'lifetime_redeemed' => (float) $b->lifetime_redeemed,
            ])
            ->toArray();
    }

    public function getCustomerHistory(Merchant $merchant, string $customerEmail, int $limit = 50): array
    {
        return RewardTransaction::byMerchant($merchant->id)
            ->byCustomer($customerEmail)
            ->with('transaction')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
