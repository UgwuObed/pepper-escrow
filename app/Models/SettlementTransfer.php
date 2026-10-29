<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a split share. Written before the money moves, so a call
 * that fails halfway leaves a record of exactly what is still owed, and a
 * retry reuses the same idempotency key so it can never move money twice.
 */
class SettlementTransfer extends Model
{
    /** Payment arrives: the share is transferred into the party's account. */
    public const KIND_TRANSFER = 'transfer';

    /** Release: the party's account pays the share out to their bank. */
    public const KIND_PAYOUT = 'payout';

    /** Refund: the share is pulled back from the party's account. */
    public const KIND_REVERSAL = 'reversal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'settlement_hold_id', 'settlement_share_id', 'kind', 'rule_id', 'release_id',
        'destination', 'connected_account_id', 'account_id',
        'amount', 'currency', 'status', 'provider_id', 'error', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function hold()
    {
        return $this->belongsTo(SettlementHold::class, 'settlement_hold_id');
    }

    public function share()
    {
        return $this->belongsTo(SettlementShare::class, 'settlement_share_id');
    }

    public function connectedAccount()
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
