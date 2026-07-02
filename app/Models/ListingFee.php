<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingFee extends Model
{
    protected $fillable = [
        'merchant_id',
        'transaction_type_id',
        'name',
        'description',
        'fee_type',
        'fee_value',
        'cap_amount',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'fee_value' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function calculateFee(float $amount): float
    {
        $fee = match ($this->fee_type) {
            'flat' => (float) $this->fee_value,
            'percentage' => $amount * (float) $this->fee_value / 100,
            default => 0,
        };

        if ($this->cap_amount && $fee > (float) $this->cap_amount) {
            $fee = (float) $this->cap_amount;
        }

        return round($fee, 2);
    }
}
