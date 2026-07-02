<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('transaction_type_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('fee_type')->comment('flat, percentage');
            $table->decimal('fee_value', 15, 2);
            $table->decimal('cap_amount', 15, 2)->nullable();
            $table->string('currency', 10)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('transaction_type_id')->references('id')->on('transaction_types')->onDelete('set null');
            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_fees');
    }
};
