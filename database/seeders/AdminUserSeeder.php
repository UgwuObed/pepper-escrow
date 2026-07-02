<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@pepperescrow.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'firstName' => 'Super',
                'lastName' => 'Admin',
                'phoneNo' => '+2348000000000',
                'job_title' => 'System Administrator',
                'account_type' => 'admin',
                'status' => true,
                'super_admin' => true,
            ]
        );
    }
}
