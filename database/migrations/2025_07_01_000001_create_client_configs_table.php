<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->integer('escrow_hold_days')->default(7);
            $table->string('settlement_schedule')->default('manual')->comment('manual, daily, weekly, monthly');
            $table->integer('settlement_day')->nullable()->comment('Day of week (1-7) or month (1-31)');
            $table->decimal('min_settlement_amount', 15, 2)->default(0);
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->json('allowed_transaction_types')->nullable()->comment('Which transaction types this client can use');
            $table->boolean('auto_release_enabled')->default(false);
            $table->boolean('require_fulfillment_confirmation')->default(true);
            $table->json('notification_settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_configs');
    }
};
