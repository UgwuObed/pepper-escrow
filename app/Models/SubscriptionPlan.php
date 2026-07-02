<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'merchant_id',
        'transaction_type_id',
        'name',
        'slug',
        'description',
        'amount',
        'currency',
        'billing_cycle',
        'cycle_interval',
        'trial_days',
        'is_active',
        'features',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cycle_interval' => 'integer',
        'trial_days' => 'integer',
        'is_active' => 'boolean',
        'features' => 'array',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }
}
