<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('transaction_type_id')->nullable()->after('appid')->constrained()->nullOnDelete();
            $table->decimal('commission_amount', 15, 2)->nullable()->after('pepperest_fee')->comment('Platform commission on this transaction');
            $table->decimal('net_amount', 15, 2)->nullable()->after('commission_amount')->comment('amount - commission');
            $table->json('metadata')->nullable()->after('gateway_response');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_type_id');
            $table->dropColumn(['commission_amount', 'net_amount', 'metadata']);
        });
    }
};
