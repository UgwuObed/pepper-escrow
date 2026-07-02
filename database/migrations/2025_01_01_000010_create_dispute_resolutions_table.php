<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_resolutions', function (Blueprint $table) {
            $table->id();
            $table->integer('dispute_id')->nullable();
            $table->string('transcode')->nullable();
            $table->text('merchant_comment')->nullable();
            $table->text('customer_comment')->nullable();
            $table->text('arbitrator_comment')->nullable();
            $table->text('resolution_desc')->nullable();
            $table->date('sitting_date')->nullable();
            $table->date('next_sitting_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_resolutions');
    }
};
