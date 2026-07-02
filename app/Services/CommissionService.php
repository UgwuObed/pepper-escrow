<?php

namespace App\Services;

use App\Models\CommissionRule;
use App\Models\Merchant;
use App\Models\Transaction;
use Exception;

class CommissionService
{
    /**
     * Find the best matching commission rule for a transaction.
     */
    public function findRule(Merchant $merchant, int $transactionTypeId, float $amount): ?CommissionRule
    {
        return CommissionRule::where('merchant_id', $merchant->id)
            ->where('transaction_type_id', $transactionTypeId)
            ->where('status', true)
            ->get()
            ->filter(fn(CommissionRule $rule) => $rule->appliesTo($amount))
            ->sortByDesc('priority')
            ->first();
    }

    /**
     * Calculate commission for a given transaction.
     *
     * @return array{commission: float, net: float, rule: CommissionRule|null}
     */
    public function calculate(Merchant $merchant, int $transactionTypeId, float $amount): array
    {
        $rule = $this->findRule($merchant, $transactionTypeId, $amount);

        if (!$rule) {
            return [
                'commission' => 0,
                'net' => $amount,
                'rule' => null,
            ];
        }

        $commission = $rule->calculateCommission($amount);
        $net = round($amount - $commission, 2);

        return [
            'commission' => $commission,
            'net' => $net,
            'rule' => $rule,
        ];
    }

    /**
     * Apply commission to an existing transaction.
     * Updates the transaction record with commission_amount and net_amount.
     */
    public function applyToTransaction(Transaction $transaction, Merchant $merchant): Transaction
    {
        $typeId = $transaction->transaction_type_id;
        if (!$typeId) {
            throw new Exception('Transaction has no transaction_type_id.');
        }

        $amount = (float) $transaction->amount;
        $result = $this->calculate($merchant, $typeId, $amount);

        $transaction->update([
            'commission_amount' => $result['commission'],
            'net_amount' => $result['net'],
        ]);

        return $transaction->fresh();
    }

    /**
     * Seed default commission rules for a merchant's transaction types.
     */
    public function seedDefaults(Merchant $merchant): void
    {
        $types = $merchant->load('transactionTypes')->transactionTypes;

        foreach ($types as $type) {
            $existing = CommissionRule::where('merchant_id', $merchant->id)
                ->where('transaction_type_id', $type->id)
                ->exists();

            if (!$existing) {
                CommissionRule::create([
                    'merchant_id' => $merchant->id,
                    'transaction_type_id' => $type->id,
                    'name' => "Default {$type->name} commission",
                    'rate_type' => 'percentage',
                    'rate_value' => 2.5,
                    'cap_amount' => null,
                    'min_amount' => null,
                    'max_amount' => null,
                    'priority' => 0,
                    'payer' => 'merchant',
                    'status' => true,
                ]);
            }
        }
    }
}
