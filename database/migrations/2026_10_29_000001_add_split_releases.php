<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releasing one held payment to several destinations.
 *
 * Until now a hold sat in one seller's own Stripe balance and a release could
 * only pay that seller, because Stripe pays a balance out to the account that
 * owns it and nowhere else. A pooled hold instead keeps the money in Peppa's
 * platform balance and a release transfers it out in shares, so a single
 * payment can settle a manufacturer, a freight agent and a tax authority at
 * once. The rule decides the shares; the hold says who fills each role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            // holding: payments are charged onto this account and held in its
            //          own balance (the original model).
            // recipient: receives shares of pooled holds by transfer.
            $table->string('account_type')->default('holding')->after('provider');
            $table->string('label')->nullable()->after('seller_email');
        });

        Schema::table('settlement_rules', function (Blueprint $table) {
            // Who gets paid when this rule fires, e.g.
            // [{"to":"logistics","percentage":10},{"to":"supplier","remainder":true}]
            $table->json('destinations')->nullable()->after('release_percentage');

            // Lets one partner run different rule sets for different kinds of
            // payment. Null applies to every hold.
            $table->string('flow')->nullable()->after('name');
        });

        Schema::table('settlement_holds', function (Blueprint $table) {
            $table->string('funding')->default('account')->after('merchant_id')
                ->comment('account: in a holding account balance; pooled: in the platform balance');
            $table->string('flow')->nullable()->after('reference');

            // Role name => connected account id, e.g. {"logistics":"acct_…"}.
            $table->json('parties')->nullable()->after('flow');
            $table->string('on_expiry')->nullable()->after('parties')
                ->comment('pooled holds: refund, or a role to release the balance to');

            $table->string('charge_id')->nullable()->after('payment_intent_id');
            $table->string('checkout_session_id')->nullable()->after('charge_id');
            $table->text('payment_url')->nullable()->after('checkout_session_id');
        });

        // A pooled hold has no single account the money sits in.
        Schema::table('settlement_holds', function (Blueprint $table) {
            $table->unsignedBigInteger('connected_account_id')->nullable()->change();
        });

        Schema::create('settlement_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_hold_id');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('release_id')->comment('groups the lines of one release');

            $table->string('destination')->comment('as the rule named it: a role, an acct_ id, or platform');
            $table->unsignedBigInteger('connected_account_id')->nullable();
            $table->string('account_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('status')->default('pending')->comment('pending, paid, failed, retained');
            $table->string('transfer_id')->nullable();
            $table->text('error')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->foreign('settlement_hold_id')->references('id')->on('settlement_holds')->onDelete('cascade');
            $table->foreign('connected_account_id')->references('id')->on('connected_accounts')->onDelete('set null');
            $table->index(['status', 'created_at']);
            $table->index('connected_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_transfers');

        Schema::table('settlement_holds', function (Blueprint $table) {
            $table->dropColumn([
                'funding', 'flow', 'parties', 'on_expiry',
                'charge_id', 'checkout_session_id', 'payment_url',
            ]);
        });

        Schema::table('settlement_rules', function (Blueprint $table) {
            $table->dropColumn(['destinations', 'flow']);
        });

        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'label']);
        });
    }
};
