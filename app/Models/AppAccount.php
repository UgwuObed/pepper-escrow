<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppAccount extends Model
{
    protected $fillable = ['appid', 'referencid', 'customer_account', 'customer_code', 'merchant_account', 'merchant_code'];
    protected $table = 'app_accounts';
    public $timestamps = false;
}
