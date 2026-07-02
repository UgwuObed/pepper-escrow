<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestRefund extends Model
{
    protected $fillable = ['posting_date', 'transcode', 'customer_email', 'merchant_email', 'description', 'amount', 'startdate', 'enddate', 'date_request', 'date_refunded', 'reason', 'request_status', 'requester', 'reject_reason'];
    protected $table = 'request_refunds';
    public $timestamps = false;
}
