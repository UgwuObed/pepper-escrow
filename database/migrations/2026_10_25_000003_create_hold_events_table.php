<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why every release happened.
 *
 * Append-only. The settlement_holds row says what a payment's state is now;
 * this says how it got there, which is what anyone asks when money moved and
 * somebody disagrees with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hold_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_hold_id');

            $table->string('event_type')
                ->comment('held, buyer_confirmed, approved, dispute_raised, dispute_resolved, released, refunded, expiry_warning, release_failed');

            $table->string('actor')->nullable()->comment('buyer, seller, admin:<id>, system');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('note')->nullable();
            $table->json('detail')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('settlement_hold_id')->references('id')->on('settlement_holds')->onDelete('cascade');
            $table->index(['settlement_hold_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_events');
    }
};
