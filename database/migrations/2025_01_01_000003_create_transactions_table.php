<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->dateTime('posting_date')->nullable();
            $table->string('transcode')->nullable()->unique();
            $table->string('tempcode')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('merchant_email')->nullable();
            $table->integer('merchantid')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('country')->nullable();
            $table->string('currency')->nullable()->default('NGN');
            $table->string('startdate')->nullable();
            $table->string('enddate')->nullable();
            $table->string('fulfill_days')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('trans_status')->nullable()->default('Pending');
            $table->dateTime('refunddate')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->dateTime('stoprefunddate')->nullable();
            $table->boolean('refunded')->default(false);
            $table->boolean('extended')->default(false);
            $table->boolean('requestextend')->default(false);
            $table->boolean('reqestrefund')->default(false);
            $table->boolean('confirmed_by_merchant')->default(false);
            $table->dateTime('confirmed_date')->nullable();
            $table->dateTime('cancelled_date')->nullable();
            $table->dateTime('insert_date')->nullable();
            $table->decimal('amountpaid', 15, 2)->nullable();
            $table->dateTime('fufill_notice_date')->nullable();
            $table->decimal('pepperest_fee', 15, 2)->nullable();
            $table->decimal('paystack_fee', 15, 2)->nullable();
            $table->decimal('RAVE_fee', 15, 2)->nullable();
            $table->boolean('request_extend')->default(false);
            $table->dateTime('stop_payment_date')->nullable();
            $table->text('reason_for_stopping')->nullable();
            $table->dateTime('refund_date')->nullable();
            $table->text('reason_for_stop_refund')->nullable();
            $table->dateTime('stop_refund_date')->nullable();
            $table->dateTime('arbitration_request_date')->nullable();
            $table->integer('order_id')->nullable();
            $table->boolean('request_refund')->default(false);
            $table->string('appid')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->text('gateway_response')->nullable();

            $table->index('transcode');
            $table->index('appid');
            $table->index('trans_status');
            $table->index('customer_email');
            $table->index('merchant_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
