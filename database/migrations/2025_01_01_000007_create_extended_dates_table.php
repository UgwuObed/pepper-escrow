<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extended_dates', function (Blueprint $table) {
            $table->id();
            $table->dateTime('posting_date')->nullable();
            $table->string('transcode')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('merchant_email')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('startdate')->nullable();
            $table->string('old_fulfill_date')->nullable();
            $table->string('new_fulfill_date')->nullable();
            $table->dateTime('date_request')->nullable();
            $table->dateTime('date_extended')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_status')->nullable();
            $table->string('requester')->nullable();
            $table->text('reject_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extended_dates');
    }
};
