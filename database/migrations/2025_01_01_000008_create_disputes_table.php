<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->integer('merchant_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('merchant_email')->nullable();
            $table->string('appid')->nullable();
            $table->string('transcode')->nullable();
            $table->string('dispute_referenceid')->nullable();
            $table->string('dispute_category')->nullable();
            $table->text('dispute_description')->nullable();
            $table->string('arbitrator_name')->nullable();
            $table->string('arbitrator_profile')->nullable();
            $table->text('final_resolution')->nullable();
            $table->date('resolution_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
