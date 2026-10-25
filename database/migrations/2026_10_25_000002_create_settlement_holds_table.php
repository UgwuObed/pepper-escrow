<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment sitting in a seller's Stripe balance, waiting on a condition.
 *
 * The money is not Peppa's and is not in Peppa's balance; this row is the
 * record of what we are holding the release switch on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('connected_account_id');
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->string('reference')->comment('our reference for this payment');
            $table->string('payment_intent_id')->nullable()->comment('Stripe pi_… on the seller account');

            $table->decimal('amount', 15, 2);
            $table->decimal('amount_released', 15, 2)->default(0);
            $table->decimal('amount_refunded', 15, 2)->default(0);
            $table->char('currency', 3)->default('GBP');

            $table->string('status')->default('held')
                ->comment('held, released, part_released, disputed, refunded, expired');

            // Stripe will not let funds sit past the seller country's limit,
            // so a hold has a hard deadline we must release before.
            $table->timestamp('held_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->unsignedBigInteger('released_by_rule_id')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('connected_account_id')->references('id')->on('connected_accounts')->onDelete('cascade');
            $table->foreign('released_by_rule_id')->references('id')->on('settlement_rules')->onDelete('set null');

            $table->unique(['merchant_id', 'reference']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_holds');
    }
};
