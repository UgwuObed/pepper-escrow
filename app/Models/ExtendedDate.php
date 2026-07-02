<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtendedDate extends Model
{
    protected $fillable = ['posting_date', 'transcode', 'customer_email', 'merchant_email', 'description', 'amount', 'startdate', 'old_fulfill_date', 'new_fulfill_date', 'date_request', 'date_extended', 'reason', 'request_status', 'requester', 'reject_reason'];
    protected $table = 'extended_dates';
    public $timestamps = false;
}
