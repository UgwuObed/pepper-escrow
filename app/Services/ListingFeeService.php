<?php

namespace App\Services;

use App\Models\ListingFee;
use App\Models\Merchant;
use App\Models\Transaction;

class ListingFeeService
{
    public function chargeListingFee(Transaction $txn, Merchant $merchant, ?int $feeId = null): ?float
    {
        $query = ListingFee::byMerchant($merchant->id)->active();

        if ($feeId) {
            $fee = $query->find($feeId);
        } else {
            $fee = $query->where(function ($q) use ($txn) {
                $q->whereNull('transaction_type_id')
                  ->orWhere('transaction_type_id', $txn->transaction_type_id);
            })->first();
        }

        if (!$fee) {
            return null;
        }

        $feeAmount = $fee->calculateFee((float) $txn->amount);

        $txn->update([
            'listing_fee_amount' => $feeAmount,
            'metadata' => array_merge($txn->metadata ?? [], [
                'listing_fee_id' => $fee->id,
                'listing_fee_name' => $fee->name,
            ]),
        ]);

        return $feeAmount;
    }

    public function getApplicableFees(Merchant $merchant, ?int $transactionTypeId = null): array
    {
        $query = ListingFee::byMerchant($merchant->id)->active();

        if ($transactionTypeId) {
            $query->where(function ($q) use ($transactionTypeId) {
                $q->whereNull('transaction_type_id')
                  ->orWhere('transaction_type_id', $transactionTypeId);
            });
        }

        return $query->get()->toArray();
    }
}
