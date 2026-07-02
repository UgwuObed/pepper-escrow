<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'firstName', 'lastName', 'phoneNo',
        'job_title', 'account_type', 'app_id', 'status', 'super_admin',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'super_admin' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function apiToken()
    {
        return $this->belongsTo(ApiToken::class, 'app_id', 'app_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'email', 'email');
    }
}
