<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionHistory extends Model
{
    protected $fillable = ['transcode', 'customer_email', 'merchant_email', 'trans_status', 'status_update_date', 'updatedby'];
    protected $table = 'transactions_history';
    public $timestamps = false;
}
