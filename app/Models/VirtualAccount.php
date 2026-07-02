<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualAccount extends Model
{
    protected $fillable = [
        'merchant_id',
        'transaction_id',
        'gateway',
        'customer_email',
        'customer_name',
        'account_number',
        'account_name',
        'bank_name',
        'provider_reference',
        'status',
        'is_permanent',
        'expires_at',
    ];

    protected $casts = [
        'is_permanent' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'assigned']);
    }

    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeByCustomer($query, string $email)
    {
        return $query->where('customer_email', $email);
    }
}
