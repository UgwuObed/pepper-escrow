<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementItem extends Model
{
    protected $fillable = [
        'settlement_id',
        'transaction_id',
        'transaction_amount',
        'commission_amount',
        'net_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'transaction_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function markPaid(): void
    {
        $this->update(['status' => 'paid']);
    }

    public function markFailed(string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'notes' => $reason,
        ]);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->whereHas('settlement', fn($q) => $q->where('merchant_id', $merchantId));
    }
}
