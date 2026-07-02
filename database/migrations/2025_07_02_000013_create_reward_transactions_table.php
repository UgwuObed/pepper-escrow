<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('reward_balance_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('customer_email');
            $table->string('type')->comment('earned, redeemed, expired, adjusted');
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->string('reference_type')->nullable()->comment('transaction, referral, bonus');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('reward_balance_id')->references('id')->on('reward_balances')->onDelete('set null');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->index(['merchant_id', 'customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_transactions');
    }
};
