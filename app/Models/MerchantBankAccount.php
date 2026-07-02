<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantBankAccount extends Model
{
    protected $fillable = [
        'merchant_id',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'currency',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'status' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
