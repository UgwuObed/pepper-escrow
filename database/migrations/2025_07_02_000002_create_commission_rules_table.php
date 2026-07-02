<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_type_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('rate_type')->default('percentage')->comment('percentage, flat');
            $table->decimal('rate_value', 15, 2)->default(0)->comment('Percentage (e.g. 2.5) or flat amount');
            $table->decimal('cap_amount', 15, 2)->nullable()->comment('Maximum commission for this rule');
            $table->decimal('min_amount', 15, 2)->nullable()->comment('Min transaction amount for this rule');
            $table->decimal('max_amount', 15, 2)->nullable()->comment('Max transaction amount for this rule');
            $table->integer('priority')->default(0)->comment('Higher priority wins');
            $table->string('payer')->default('merchant')->comment('merchant, customer');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
