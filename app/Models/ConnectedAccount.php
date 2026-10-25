<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectedAccount extends Model
{
    protected $fillable = [
        'merchant_id',
        'provider',
        'account_id',
        'seller_email',
        'country',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'payout_schedule',
        'losses_payer',
        'dashboard_type',
        'holding_period_days',
        'requirements_due',
        'status_synced_at',
    ];

    protected $casts = [
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        'requirements_due' => 'array',
        'status_synced_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Whether this account can safely take a held payment.
     *
     * All four conditions matter, and Stripe will happily process a charge
     * when any of them is wrong. Losses falling on the platform puts Peppa
     * back on the hook; any Stripe-hosted dashboard gives the seller a way to
     * release funds we are supposed to be holding.
     */
    public function canHoldFunds(): bool
    {
        return $this->charges_enabled
            && $this->payout_schedule === 'manual'
            && $this->losses_payer === 'stripe'
            && $this->dashboard_type === 'none';
    }

    /**
     * Why this account cannot hold funds, for the operator screen.
     *
     * @return array<int, string>
     */
    public function holdBlockers(): array
    {
        $blockers = [];

        if (!$this->charges_enabled) {
            $blockers[] = 'Stripe has not enabled charges on this account yet.';
        }

        if ($this->payout_schedule !== 'manual') {
            $blockers[] = 'Payouts are not set to manual, so funds would be released automatically.';
        }

        if ($this->losses_payer === 'application') {
            $blockers[] = 'Losses fall on the platform for this account, not Stripe.';
        }

        if ($this->dashboard_type !== 'none') {
            $blockers[] = sprintf(
                'The seller has a %s Stripe dashboard and could pay themselves out.',
                $this->dashboard_type ?? 'unknown'
            );
        }

        return $blockers;
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeReadyToCharge($query)
    {
        return $query->where('charges_enabled', true)
            ->where('payout_schedule', 'manual')
            ->where('losses_payer', 'stripe');
    }
}
