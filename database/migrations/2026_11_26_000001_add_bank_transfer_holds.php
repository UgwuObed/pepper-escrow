<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank-transfer holds, collected through Modulr.
 *
 * Each hold gets its own Modulr account (a UK sort code and account number,
 * or an IBAN) for the payer to send money to, so every incoming transfer
 * names its own payment. The money stays in that account until a release
 * pays each participant out to their bank account.
 *
 * Participants of these holds are paid to a bank account rather than a
 * Stripe account, so a participant can now carry bank details instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->string('payee_name')->nullable()->after('label');
            $table->string('sort_code', 6)->nullable()->after('payee_name');
            $table->string('bank_account_number', 8)->nullable()->after('sort_code');
            $table->string('iban', 34)->nullable()->after('bank_account_number');
            $table->string('bic', 11)->nullable()->after('iban');
        });

        Schema::table('settlement_holds', function (Blueprint $table) {
            // The Modulr account this hold's money is paid into and held in.
            $table->string('bank_account_id')->nullable()->after('payment_url');
            // What the payer is told to pay to: sort code / account number or IBAN / BIC.
            $table->json('bank_details')->nullable()->after('bank_account_id');
            // Every transfer received into the account, keyed by Modulr's payment id.
            $table->json('payins')->nullable()->after('bank_details');

            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_holds', function (Blueprint $table) {
            $table->dropIndex(['bank_account_id']);
            $table->dropColumn(['bank_account_id', 'bank_details', 'payins']);
        });

        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn(['payee_name', 'sort_code', 'bank_account_number', 'iban', 'bic']);
        });
    }
};
