<?php

namespace App\Models;

use Database\Factories\SettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    use HasFactory;

    protected $factory = SettlementFactory::class;
    protected $fillable = [
        'merchant_id',
        'batch_number',
        'status',
        'total_amount',
        'total_commission',
        'net_amount',
        'item_count',
        'currency',
        'payment_gateway',
        'gateway_transfer_ref',
        'processed_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'item_count' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(SettlementItem::class);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(?string $gatewayRef = null): void
    {
        $this->update([
            'status' => 'completed',
            'gateway_transfer_ref' => $gatewayRef ?? $this->gateway_transfer_ref,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $notes = null): void
    {
        $this->update([
            'status' => 'failed',
            'notes' => $notes ? ($this->notes ? $this->notes . "\n" . $notes : $notes) : $this->notes,
        ]);
    }
}
