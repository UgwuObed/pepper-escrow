<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    protected $fillable = ['uri', 'method', 'params', 'api_key', 'ip_address', 'time', 'request_date', 'authorized', 'response_code'];
    protected $table = 'request_logs';
    public $timestamps = false;
}
