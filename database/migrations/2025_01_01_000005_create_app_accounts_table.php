<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('appid')->nullable();
            $table->string('referenceid')->nullable();
            $table->string('customer_account')->nullable();
            $table->string('customer_code')->nullable();
            $table->string('merchant_account')->nullable();
            $table->string('merchant_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_accounts');
    }
};
