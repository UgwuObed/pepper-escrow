<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->integer('app_id')->unique();
            $table->string('api_key');
            $table->string('api_secret')->nullable();
            $table->boolean('status')->default(true);
            $table->string('payment_gateway')->nullable()->comment('Client-specific payment gateway override');
            $table->json('gateway_config')->nullable()->comment('Client-specific gateway credentials');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
