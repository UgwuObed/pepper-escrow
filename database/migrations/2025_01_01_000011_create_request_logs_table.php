<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('uri')->nullable();
            $table->string('method')->nullable();
            $table->text('params')->nullable();
            $table->string('api_key')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('time')->nullable();
            $table->dateTime('request_date')->nullable();
            $table->boolean('authorized')->default(false);
            $table->integer('response_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
