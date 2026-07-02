<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $factory = WalletFactory::class;
    protected $fillable = [
        'merchant_id',
        'user_identifier',
        'currency',
        'balance',
        'ledger_balance',
        'hold_balance',
        'type',
        'label',
        'status',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'ledger_balance' => 'decimal:2',
        'hold_balance' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function availableBalance(): float
    {
        return (float) ($this->ledger_balance - $this->hold_balance);
    }

    public function scopeByMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public static function findByUser(string $userIdentifier, string $currency = 'NGN', string $type = 'fiat', ?int $merchantId = null): ?self
    {
        $query = self::where('user_identifier', $userIdentifier)
            ->where('currency', $currency)
            ->where('type', $type);

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        return $query->first();
    }
}
