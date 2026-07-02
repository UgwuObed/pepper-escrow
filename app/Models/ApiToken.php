<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    use HasFactory;

    protected $factory = ApiTokenFactory::class;
    protected $fillable = ['app_id', 'api_key', 'api_secret', 'status', 'payment_gateway', 'gateway_config', 'merchant_id', 'config'];
    protected $table = 'api_tokens';
    public $timestamps = false;

    protected $casts = [
        'gateway_config' => 'array',
        'config' => 'array',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'appid', 'app_id');
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
