<?php

namespace App\Models;

use Database\Factories\TransactionTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
    use HasFactory;

    protected $factory = TransactionTypeFactory::class;
    protected $fillable = [
        'merchant_id',
        'name',
        'slug',
        'description',
        'supports_escrow',
        'requires_fulfillment',
        'status',
    ];

    protected $casts = [
        'supports_escrow' => 'boolean',
        'requires_fulfillment' => 'boolean',
        'status' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function commissionRules()
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public static function getDefaults(int $merchantId): array
    {
        return [
            [
                'merchant_id' => $merchantId,
                'name' => 'Escrow',
                'slug' => 'escrow',
                'description' => 'Standard escrow transaction — funds held until both parties fulfill.',
                'supports_escrow' => true,
                'requires_fulfillment' => true,
            ],
            [
                'merchant_id' => $merchantId,
                'name' => 'Direct Sale',
                'slug' => 'direct_sale',
                'description' => 'Direct payment — funds released immediately.',
                'supports_escrow' => false,
                'requires_fulfillment' => false,
            ],
            [
                'merchant_id' => $merchantId,
                'name' => 'Auction',
                'slug' => 'auction',
                'description' => 'Auction marketplace transaction.',
                'supports_escrow' => true,
                'requires_fulfillment' => true,
            ],
            [
                'merchant_id' => $merchantId,
                'name' => 'Barter',
                'slug' => 'barter',
                'description' => 'Trade by barter — non-monetary exchange with escrow.',
                'supports_escrow' => true,
                'requires_fulfillment' => true,
            ],
            [
                'merchant_id' => $merchantId,
                'name' => 'Listing Fee',
                'slug' => 'listing_fee',
                'description' => 'One-time payment for listing or promotional services.',
                'supports_escrow' => false,
                'requires_fulfillment' => false,
            ],
            [
                'merchant_id' => $merchantId,
                'name' => 'Subscription',
                'slug' => 'subscription',
                'description' => 'Recurring subscription billing.',
                'supports_escrow' => false,
                'requires_fulfillment' => false,
            ],
        ];
    }
}
