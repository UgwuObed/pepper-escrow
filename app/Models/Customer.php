<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $factory = CustomerFactory::class;
    protected $fillable = [
        'name', 'email', 'businessname', 'phone', 'usertype', 'merchantid',
        'accountno', 'bankcode', 'bvn', 'bvn_verified', 'dob', 'address',
        'city', 'state', 'country', 'idcard'
    ];

    protected $table = 'customers';
    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}
