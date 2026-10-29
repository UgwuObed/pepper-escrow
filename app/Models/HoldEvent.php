<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of what happened to a hold. Never updated or deleted:
 * this is the answer to "why did this money move".
 */
class HoldEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'settlement_hold_id', 'event_type', 'actor',
        'rule_id', 'amount', 'note', 'detail',
    ];

    protected $casts = [
        'detail' => 'array',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function hold()
    {
        return $this->belongsTo(SettlementHold::class, 'settlement_hold_id');
    }
}
