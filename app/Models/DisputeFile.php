<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisputeFile extends Model
{
    protected $fillable = ['dispute_id', 'file_link'];
    protected $table = 'dispute_files';
    public $timestamps = false;
}
