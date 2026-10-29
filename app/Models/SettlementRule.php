<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementRule extends Model
{
    public const CONDITION_BUYER_CONFIRMATION = 'buyer_confirmation';
    public const CONDITION_ELAPSED_DAYS = 'elapsed_days';
    public const CONDITION_MANUAL_APPROVAL = 'manual_approval';

    /** A named milestone, optionally confirmed by a named party. */
    public const CONDITION_MILESTONE = 'milestone';

    public const CONDITIONS = [
        self::CONDITION_BUYER_CONFIRMATION,
        self::CONDITION_ELAPSED_DAYS,
        self::CONDITION_MANUAL_APPROVAL,
        self::CONDITION_MILESTONE,
    ];

    /** A share that is Peppa's own fee and is never transferred. */
    public const PLATFORM_DESTINATION = 'platform';

    protected $fillable = [
        'merchant_id', 'name', 'flow', 'condition_type', 'config',
        'release_percentage', 'parties', 'priority', 'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'parties' => 'array',
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

    /** The milestone a milestone rule waits for. */
    public function milestone(): ?string
    {
        return $this->config['milestone'] ?? null;
    }

    /** The party whose confirmation counts, or null when anyone's does. */
    public function confirmedBy(): ?string
    {
        return $this->config['confirmed_by'] ?? null;
    }

    /**
     * A rule that names parties only releases those parties' shares, so it
     * only makes sense on a split hold. A rule without parties releases
     * everything it covers, on any hold.
     */
    public function namesParties(): bool
    {
        return !empty($this->parties);
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
