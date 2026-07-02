<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisputeResolution extends Model
{
    protected $fillable = [
        'dispute_id', 'transcode', 'merchant_comment', 'customer_comment',
        'arbitrator_comment', 'resolution_desc', 'sitting_date', 'next_sitting_date'
    ];

    protected $table = 'dispute_resolutions';
    public $timestamps = false;
}
