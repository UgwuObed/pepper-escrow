<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientConfig extends Model
{
    protected $fillable = [
        'merchant_id',
        'escrow_hold_days',
        'settlement_schedule',
        'settlement_day',
        'min_settlement_amount',
        'webhook_url',
        'webhook_secret',
        'allowed_transaction_types',
        'auto_release_enabled',
        'require_fulfillment_confirmation',
        'notification_settings',
        'metadata',
    ];

    protected $casts = [
        'allowed_transaction_types' => 'array',
        'notification_settings' => 'array',
        'metadata' => 'array',
        'auto_release_enabled' => 'boolean',
        'require_fulfillment_confirmation' => 'boolean',
        'escrow_hold_days' => 'integer',
        'min_settlement_amount' => 'decimal:2',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public static function defaultConfig(): array
    {
        return [
            'escrow_hold_days' => 7,
            'settlement_schedule' => 'manual',
            'settlement_day' => null,
            'min_settlement_amount' => 0,
            'webhook_url' => null,
            'webhook_secret' => null,
            'allowed_transaction_types' => ['escrow'],
            'auto_release_enabled' => false,
            'require_fulfillment_confirmation' => true,
            'notification_settings' => [
                'on_payment' => true,
                'on_release' => true,
                'on_dispute' => true,
                'on_settlement' => true,
            ],
            'metadata' => [],
        ];
    }
}
