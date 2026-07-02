<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionService
{
    protected EscrowPaymentService $paymentService;

    public function __construct(EscrowPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function subscribe(
        SubscriptionPlan $plan,
        Merchant $merchant,
        string $customerEmail,
        ?string $customerName = null,
        bool $startTrial = false,
    ): Subscription {
        $existing = Subscription::byCustomer($customerEmail)
            ->where('plan_id', $plan->id)
            ->whereIn('status', ['active', 'paused'])
            ->first();

        if ($existing) {
            throw new \RuntimeException('Customer already has an active subscription to this plan.');
        }

        $now = now();
        $trialEndsAt = $startTrial && $plan->trial_days > 0
            ? $now->copy()->addDays($plan->trial_days)
            : null;

        $nextBillingAt = $trialEndsAt
            ? $trialEndsAt
            : $now->copy()->addDays($this->cycleDays($plan));

        return Subscription::create([
            'merchant_id' => $merchant->id,
            'plan_id' => $plan->id,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName ?? $customerEmail,
            'status' => 'active',
            'starts_at' => $now,
            'trial_ends_at' => $trialEndsAt,
            'next_billing_at' => $nextBillingAt,
            'billing_count' => 0,
        ]);
    }

    public function generateInvoice(Subscription $subscription): SubscriptionInvoice
    {
        $plan = $subscription->plan;
        $period = $subscription->billing_count + 1;

        return SubscriptionInvoice::create([
            'merchant_id' => $subscription->merchant_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-' . strtoupper(Str::random(12)),
            'amount' => $plan->amount,
            'currency' => $plan->currency,
            'status' => 'pending',
            'due_date' => $subscription->next_billing_at ?? now(),
            'billing_period' => $period,
        ]);
    }

    public function processBilling(Subscription $subscription): ?Transaction
    {
        if (!$subscription->isActive() && $subscription->status !== 'paused') {
            return null;
        }

        if ($subscription->isOnTrial()) {
            return null;
        }

        if ($subscription->next_billing_at && $subscription->next_billing_at->isFuture()) {
            return null;
        }

        $plan = $subscription->plan;

        DB::beginTransaction();
        try {
            $invoice = $this->generateInvoice($subscription);

            $transaction = Transaction::create([
                'posting_date' => now(),
                'transcode' => 'SUB-' . strtoupper(Str::random(16)),
                'customer_email' => $subscription->customer_email,
                'merchant_email' => $subscription->plan->merchant->email ?? '',
                'merchantid' => $subscription->merchant_id,
                'description' => "Subscription: {$plan->name} (Invoice {$invoice->invoice_number})",
                'amount' => $plan->amount,
                'country' => 'NG',
                'currency' => $plan->currency,
                'startdate' => now(),
                'enddate' => now()->addDays(30),
                'fulfill_days' => '0 days',
                'payment_date' => now(),
                'trans_status' => 'Pending',
                'pepperest_fee' => 0,
                'appid' => $subscription->merchant_id,
                'transaction_type_id' => $plan->transaction_type_id,
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $invoice->id,
                    'plan_name' => $plan->name,
                    'billing_period' => $invoice->billing_period,
                ],
            ]);

            $invoice->update(['transaction_id' => $transaction->id]);

            $subscription->markBilled();

            DB::commit();

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription billing failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markInvoicePaid(SubscriptionInvoice $invoice, ?int $transactionId = null): void
    {
        $invoice->markPaid($transactionId);

        $transaction = $invoice->transaction;
        if ($transaction) {
            $transaction->update([
                'payment_status' => 'Paid',
                'trans_status' => 'Open',
                'payment_date' => now(),
            ]);
        }
    }

    public function processDueBillings(): int
    {
        $count = 0;
        $subscriptions = Subscription::dueForBilling()->with('plan.merchant')->get();

        foreach ($subscriptions as $subscription) {
            try {
                $this->processBilling($subscription);
                $count++;
            } catch (\Exception $e) {
                Log::error('Scheduled billing error', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function getNextBillingDate(SubscriptionPlan $plan, ?Carbon $from = null): Carbon
    {
        $from = $from ?? now();
        return $from->copy()->addDays($this->cycleDays($plan));
    }

    protected function cycleDays(SubscriptionPlan $plan): int
    {
        return match ($plan->billing_cycle) {
            'daily'   => 1 * $plan->cycle_interval,
            'weekly'  => 7 * $plan->cycle_interval,
            'monthly' => 30 * $plan->cycle_interval,
            'yearly'  => 365 * $plan->cycle_interval,
            default   => 30,
        };
    }
}
