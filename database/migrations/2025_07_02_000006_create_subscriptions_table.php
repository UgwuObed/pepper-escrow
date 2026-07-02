<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('status')->default('active')->comment('active, paused, cancelled, expired');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('gateway')->nullable()->comment('paystack, flutterwave');
            $table->string('gateway_subscription_ref')->nullable()->comment('Gateway recurring reference');
            $table->string('gateway_customer_ref')->nullable()->comment('Gateway customer code');
            $table->unsignedInteger('billing_count')->default(0);
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('subscription_plans')->onDelete('cascade');
            $table->index(['merchant_id', 'customer_email']);
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
