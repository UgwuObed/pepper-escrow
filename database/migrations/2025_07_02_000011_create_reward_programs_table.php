<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('reward_type')->comment('points, cashback, discount_percentage, discount_flat');
            $table->decimal('reward_value', 15, 2)->comment('Points per unit or percentage/flat value');
            $table->decimal('min_transaction_amount', 15, 2)->nullable();
            $table->json('applicable_type_ids')->nullable()->comment('Transaction type IDs this applies to');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_programs');
    }
};
