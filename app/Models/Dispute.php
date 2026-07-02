<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $fillable = [
        'merchant_id', 'customer_id', 'customer_email', 'merchant_email', 'appid',
        'transcode', 'dispute_referenceid', 'dispute_category', 'dispute_description',
        'arbitrator_name', 'arbitrator_profile', 'final_resolution', 'resolution_date'
    ];

    protected $table = 'disputes';
    public $timestamps = false;
}
