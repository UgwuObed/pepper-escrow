<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'merchant_id',
        'plan_id',
        'customer_email',
        'customer_name',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'next_billing_at',
        'cancelled_at',
        'cancellation_reason',
        'gateway',
        'gateway_subscription_ref',
        'gateway_customer_ref',
        'billing_count',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'next_billing_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'billing_count' => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeByCustomer($query, string $email)
    {
        return $query->where('customer_email', $email);
    }

    public function scopeDueForBilling($query)
    {
        return $query->whereIn('status', ['active', 'paused'])
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', now());
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function cancel(string $reason = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'ends_at' => $this->next_billing_at,
        ]);
    }

    public function markBilled(): void
    {
        $plan = $this->plan;
        $nextBilling = $this->calculateNextBillingDate($plan);

        $this->update([
            'billing_count' => $this->billing_count + 1,
            'next_billing_at' => $nextBilling,
        ]);
    }

    public function calculateNextBillingDate(SubscriptionPlan $plan): Carbon
    {
        $base = $this->next_billing_at ?? now();

        return match ($plan->billing_cycle) {
            'daily'    => $base->copy()->addDays($plan->cycle_interval),
            'weekly'   => $base->copy()->addWeeks($plan->cycle_interval),
            'monthly'  => $base->copy()->addMonths($plan->cycle_interval),
            'yearly'   => $base->copy()->addYears($plan->cycle_interval),
            default    => $base->copy()->addMonth(),
        };
    }
}
