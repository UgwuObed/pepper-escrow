<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('gateway')->comment('paystack, flutterwave');
            $table->string('customer_email');
            $table->string('customer_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->string('bank_name');
            $table->string('provider_reference')->nullable()->comment('Gateway reference/id for the virtual account');
            $table->string('status')->default('active')->comment('active, assigned, dormant, closed');
            $table->boolean('is_permanent')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->index(['merchant_id', 'status']);
            $table->unique(['gateway', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_accounts');
    }
};
