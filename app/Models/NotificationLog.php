<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'merchant_id',
        'channel',
        'event',
        'recipient',
        'subject',
        'body',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'reference_type',
        'reference_id',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function scopeByMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeByEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function markSent(int $code = 200, ?string $body = null): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'response_code' => $code,
            'response_body' => $body,
        ]);
    }

    public function markFailed(int $code = 0, ?string $body = null): void
    {
        $this->update([
            'status' => 'failed',
            'response_code' => $code,
            'response_body' => $body,
        ]);
    }
}
