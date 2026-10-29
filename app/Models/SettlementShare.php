<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One party's share of a split payment.
 *
 * The share is transferred into the party's own account when the payment
 * arrives and stays there, locked, until a release pays it out. Released is
 * what has been decided; paid out is what has actually left. The gap between
 * them is a payout still being retried.
 */
class SettlementShare extends Model
{
    protected $fillable = [
        'settlement_hold_id', 'destination', 'connected_account_id', 'account_id',
        'amount', 'amount_released', 'amount_paid_out', 'amount_refunded', 'currency',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_released' => 'decimal:2',
        'amount_paid_out' => 'decimal:2',
        'amount_refunded' => 'decimal:2',
    ];

    public function hold()
    {
        return $this->belongsTo(SettlementHold::class, 'settlement_hold_id');
    }

    public function connectedAccount()
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    public function movements()
    {
        return $this->hasMany(SettlementTransfer::class)->orderBy('id');
    }

    /** Peppa's own fee: never transferred, so nothing to lock or pay out. */
    public function isPlatform(): bool
    {
        return $this->connected_account_id === null;
    }

    /** Still locked in the party's account, neither released nor refunded. */
    public function remaining(): float
    {
        return round((float) $this->amount - (float) $this->amount_released - (float) $this->amount_refunded, 2);
    }

    /** The transfer that put this share into the party's account, once it succeeded. */
    public function allocation(): ?SettlementTransfer
    {
        return $this->movements
            ->where('kind', SettlementTransfer::KIND_TRANSFER)
            ->firstWhere('status', SettlementTransfer::STATUS_PAID);
    }
}
