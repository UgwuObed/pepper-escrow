<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectedAccount extends Model
{
    /** Payments are charged onto this account and held in its own balance. */
    public const TYPE_HOLDING = 'holding';

    /** A participant: holds its own share of split payments until released. */
    public const TYPE_RECIPIENT = 'recipient';

    public const TYPES = [self::TYPE_HOLDING, self::TYPE_RECIPIENT];

    protected $fillable = [
        'merchant_id',
        'provider',
        'account_type',
        'account_id',
        'seller_email',
        'label',
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
        return $this->account_type !== self::TYPE_RECIPIENT
            && $this->charges_enabled
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

        if ($this->account_type === self::TYPE_RECIPIENT) {
            return ['This is a recipient account. It receives released shares and cannot hold payments itself.'];
        }

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

    public function isRecipient(): bool
    {
        return $this->account_type === self::TYPE_RECIPIENT;
    }

    /**
     * Whether a share of a split payment can be placed here and held.
     *
     * A share sits in the party's own balance from the moment it is paid
     * until it is released, so the account needs the same lock as a holding
     * account: manual payouts and no dashboard from which to pay itself out.
     */
    public function canHoldShares(): bool
    {
        return $this->isRecipient()
            && $this->details_submitted
            && $this->payouts_enabled
            && $this->payout_schedule === 'manual'
            && $this->dashboard_type === 'none';
    }

    /**
     * @return array<int, string>
     */
    public function shareBlockers(): array
    {
        if (!$this->isRecipient()) {
            return ['This is a holding account. Split payments are shared between participant accounts.'];
        }

        $blockers = [];

        if (!$this->details_submitted) {
            $blockers[] = 'The account holder has not finished onboarding.';
        }

        if (!$this->payouts_enabled) {
            $blockers[] = 'Payouts are not enabled on this account yet.';
        }

        if ($this->payout_schedule !== 'manual') {
            $blockers[] = 'Payouts are not set to manual, so a share would be paid out before it is released.';
        }

        if ($this->dashboard_type !== 'none') {
            $blockers[] = 'The account holder has a dashboard and could pay themselves out.';
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
