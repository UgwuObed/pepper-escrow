<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a split release: a share of a pooled hold on its way to one
 * destination. Lines are written before any money moves, so a release that
 * fails halfway leaves a record of exactly which shares are still owed.
 */
class SettlementTransfer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    /** The platform's own share: nothing is transferred, it stays put. */
    public const STATUS_RETAINED = 'retained';

    protected $fillable = [
        'settlement_hold_id', 'rule_id', 'release_id',
        'destination', 'connected_account_id', 'account_id',
        'amount', 'currency', 'status', 'transfer_id', 'error', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function hold()
    {
        return $this->belongsTo(SettlementHold::class, 'settlement_hold_id');
    }

    public function connectedAccount()
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
