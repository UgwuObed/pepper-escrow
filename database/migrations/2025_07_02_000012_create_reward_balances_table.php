<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('customer_email');
            $table->string('reward_type')->comment('points, cashback');
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('lifetime_earned', 15, 2)->default(0);
            $table->decimal('lifetime_redeemed', 15, 2)->default(0);
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->unique(['merchant_id', 'customer_email', 'reward_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_balances');
    }
};
