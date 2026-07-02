<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('businessname')->nullable();
            $table->string('phone')->nullable();
            $table->string('usertype')->nullable();
            $table->string('merchantid')->nullable()->unique();
            $table->string('accountno')->nullable();
            $table->string('bankcode')->nullable();
            $table->string('bvn')->nullable();
            $table->boolean('bvn_verified')->default(false);
            $table->string('dob')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->text('idcard')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
