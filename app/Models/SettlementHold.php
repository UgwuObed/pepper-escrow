<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementHold extends Model
{
    public const STATUS_HELD = 'held';
    public const STATUS_PART_RELEASED = 'part_released';
    public const STATUS_RELEASED = 'released';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_EXPIRED = 'expired';

    /** A split hold whose payment has been requested but not yet made. */
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    /** The money sits in one holding account's own balance. */
    public const FUNDING_ACCOUNT = 'account';

    /**
     * The payment is divided on arrival and each share sits in its party's
     * own account until released.
     */
    public const FUNDING_SPLIT = 'split';

    /**
     * Paid by bank transfer into an account opened for this hold alone, and
     * held there. Divided into shares like a split hold, but each share is
     * paid out from the hold's account to its participant's bank account.
     */
    public const FUNDING_BANK_TRANSFER = 'bank_transfer';

    protected $fillable = [
        'merchant_id', 'funding', 'connected_account_id', 'transaction_id',
        'reference', 'flow', 'parties', 'split', 'on_expiry',
        'payment_intent_id', 'charge_id', 'checkout_session_id', 'payment_url',
        'bank_account_id', 'bank_details', 'payins',
        'amount', 'amount_released', 'amount_refunded', 'currency',
        'status', 'held_at', 'hold_expires_at', 'released_at',
        'released_by_rule_id', 'release_reason',
    ];

    protected $casts = [
        'parties' => 'array',
        'split' => 'array',
        'bank_details' => 'array',
        'payins' => 'array',
        'amount' => 'decimal:2',
        'amount_released' => 'decimal:2',
        'amount_refunded' => 'decimal:2',
        'held_at' => 'datetime',
        'hold_expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function connectedAccount()
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    public function events()
    {
        return $this->hasMany(HoldEvent::class)->orderBy('created_at');
    }

    public function rule()
    {
        return $this->belongsTo(SettlementRule::class, 'released_by_rule_id');
    }

    public function transfers()
    {
        return $this->hasMany(SettlementTransfer::class)->orderBy('id');
    }

    public function shares()
    {
        return $this->hasMany(SettlementShare::class)->orderBy('id');
    }

    /**
     * Divided into shares between participants. True for bank-transfer holds
     * too: they are shared, released and refunded share by share in the same
     * way, and only differ in where the money sits and how it moves.
     */
    public function isSplit(): bool
    {
        return in_array($this->funding, [self::FUNDING_SPLIT, self::FUNDING_BANK_TRANSFER], true);
    }

    public function isBankTransfer(): bool
    {
        return $this->funding === self::FUNDING_BANK_TRANSFER;
    }

    /** Total received so far into the hold's bank account, in its currency. */
    public function amountReceived(): float
    {
        return round(collect($this->payins ?? [])
            ->filter(fn ($p) => strtoupper($p['currency'] ?? '') === $this->currency)
            ->sum('amount'), 2);
    }

    /** Whoever paid in, for sending a refund back. The first payer is used. */
    public function payer(): ?array
    {
        return collect($this->payins ?? [])->pluck('payer')->filter()->first();
    }

    /** The connected account id filling a role on this payment, if any. */
    public function party(string $role): ?string
    {
        return $this->parties[$role] ?? null;
    }

    /** What is still held: not yet released and not refunded. */
    public function remaining(): float
    {
        return round((float) $this->amount - (float) $this->amount_released - (float) $this->amount_refunded, 2);
    }

    /**
     * A disputed hold never releases on a rule. Freezing is the whole point:
     * a timer that keeps running through a dispute would pay out the money
     * being argued over.
     */
    public function isReleasable(): bool
    {
        return in_array($this->status, [self::STATUS_HELD, self::STATUS_PART_RELEASED], true)
            && $this->remaining() > 0;
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /**
     * Stripe's holding period is a compliance limit, not a soft one, so a
     * hold approaching it has to be released whether a condition fired or not.
     */
    public function expiresWithin(int $days): bool
    {
        return $this->hold_expires_at !== null
            && $this->hold_expires_at->lessThanOrEqualTo(now()->addDays($days));
    }

    public function hasExpired(): bool
    {
        return $this->hold_expires_at !== null && $this->hold_expires_at->isPast();
    }

    public function scopeReleasable($query)
    {
        return $query->whereIn('status', [self::STATUS_HELD, self::STATUS_PART_RELEASED]);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }
}
