<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_history', function (Blueprint $table) {
            $table->id();
            $table->string('transcode')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('merchant_email')->nullable();
            $table->string('trans_status')->nullable();
            $table->dateTime('status_update_date')->nullable();
            $table->string('updatedby')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_history');
    }
};
