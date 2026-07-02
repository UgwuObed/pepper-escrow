<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_identifier')->comment('Client\'s user ID (e.g. user_abc123)');
            $table->string('currency', 3)->default('NGN');
            $table->decimal('balance', 15, 2)->default(0)->comment('Available balance');
            $table->decimal('ledger_balance', 15, 2)->default(0)->comment('Total balance including holds');
            $table->decimal('hold_balance', 15, 2)->default(0)->comment('Amount currently on hold');
            $table->string('type', 20)->default('fiat')->comment('fiat, reward');
            $table->string('label')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['merchant_id', 'user_identifier', 'currency', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
