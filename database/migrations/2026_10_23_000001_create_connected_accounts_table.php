<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers' Stripe connected accounts.
 *
 * Funds are held in the seller's own Stripe balance rather than Peppa's, so
 * this table is the record of who can be paid, whether their account is in a
 * state where holding is safe, and how long Stripe will let us hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('provider')->default('stripe');
            $table->string('account_id')->comment('Stripe acct_… id');
            $table->string('seller_email');
            $table->char('country', 2);

            // Onboarding state, mirrored from Stripe so we can refuse a charge
            // without a round trip. Stripe remains the source of truth.
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->string('payout_schedule')->nullable()->comment('manual once the hold is armed');

            // The settlement model only holds if losses fall on Stripe and the
            // seller cannot pay themselves out. Recorded per account so a
            // misconfigured one can be caught before it takes money.
            $table->string('losses_payer')->nullable()->comment('stripe or application');
            $table->string('dashboard_type')->nullable()->comment('express, full or none');

            $table->unsignedSmallInteger('holding_period_days')->default(90);
            $table->json('requirements_due')->nullable();
            $table->timestamp('status_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->unique(['provider', 'account_id']);
            $table->index(['merchant_id', 'charges_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
