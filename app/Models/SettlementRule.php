<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementRule extends Model
{
    public const CONDITION_BUYER_CONFIRMATION = 'buyer_confirmation';
    public const CONDITION_ELAPSED_DAYS = 'elapsed_days';
    public const CONDITION_MANUAL_APPROVAL = 'manual_approval';

    public const CONDITIONS = [
        self::CONDITION_BUYER_CONFIRMATION,
        self::CONDITION_ELAPSED_DAYS,
        self::CONDITION_MANUAL_APPROVAL,
    ];

    protected $fillable = [
        'merchant_id', 'name', 'condition_type', 'config',
        'release_percentage', 'priority', 'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Days to wait, for an elapsed_days rule. */
    public function days(): int
    {
        return (int) ($this->config['days'] ?? 0);
    }

    public function releasesInFull(): bool
    {
        return (int) $this->release_percentage >= 100;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }
}
