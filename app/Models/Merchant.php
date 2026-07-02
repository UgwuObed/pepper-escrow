<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Merchant extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'business_name',
        'email',
        'password',
        'phone',
        'website',
        'webhook_url',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function transactionTypes()
    {
        return $this->hasMany(TransactionType::class);
    }

    public function apiToken()
    {
        return $this->hasOne(ApiToken::class, 'app_id', 'id');
    }

    public function clientConfig()
    {
        return $this->hasOne(ClientConfig::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(MerchantBankAccount::class);
    }

    public function defaultBankAccount()
    {
        return $this->hasOne(MerchantBankAccount::class)->where('is_default', true);
    }
}
