<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardProgram extends Model
{
    protected $fillable = [
        'merchant_id',
        'name',
        'description',
        'reward_type',
        'reward_value',
        'min_transaction_amount',
        'applicable_type_ids',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'reward_value' => 'decimal:2',
        'min_transaction_amount' => 'decimal:2',
        'applicable_type_ids' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function appliesToTransaction(Transaction $txn): bool
    {
        if ($this->min_transaction_amount && (float) $txn->amount < (float) $this->min_transaction_amount) {
            return false;
        }

        $typeIds = $this->applicable_type_ids ?? [];
        if (!empty($typeIds) && !in_array($txn->transaction_type_id, $typeIds)) {
            return false;
        }

        return true;
    }

    public function calculateReward(float $amount): float
    {
        return match ($this->reward_type) {
            'points' => floor($amount * (float) $this->reward_value),
            'cashback' => round($amount * (float) $this->reward_value / 100, 2),
            'discount_percentage' => round($amount * (float) $this->reward_value / 100, 2),
            'discount_flat' => min((float) $this->reward_value, $amount),
            default => 0,
        };
    }
}
