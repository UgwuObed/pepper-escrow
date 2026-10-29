<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What releases a held payment.
 *
 * A partner's rules are evaluated in priority order; the first one that
 * matches releases. Rules do not express "and" — a payment held on two
 * conditions at once is a different shape of problem and would need a rule
 * tree rather than a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');

            $table->string('condition_type')
                ->comment('buyer_confirmation, elapsed_days, manual_approval');

            // Per-condition settings, e.g. {"days": 7} for elapsed_days.
            $table->json('config')->nullable();

            // Partial releases: 100 means the whole held amount.
            $table->unsignedTinyInteger('release_percentage')->default(100);

            $table->unsignedSmallInteger('priority')->default(100)
                ->comment('lower runs first');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_rules');
    }
};
