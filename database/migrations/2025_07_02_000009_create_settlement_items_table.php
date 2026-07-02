<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_id');
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('transaction_amount', 15, 2);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('status')->default('pending')->comment('pending, included, paid, failed, skipped');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->unique(['settlement_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
    }
};
