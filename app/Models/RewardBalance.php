<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardBalance extends Model
{
    protected $fillable = [
        'merchant_id',
        'customer_email',
        'reward_type',
        'balance',
        'lifetime_earned',
        'lifetime_redeemed',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'lifetime_earned' => 'decimal:2',
        'lifetime_redeemed' => 'decimal:2',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions()
    {
        return $this->hasMany(RewardTransaction::class, 'reward_balance_id');
    }

    public function scopeByCustomer($query, string $email)
    {
        return $query->where('customer_email', $email);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }
}
