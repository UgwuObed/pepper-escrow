<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split payments are divided when they are paid, not when they are released.
 *
 * The first version held a split payment in Peppa's platform balance and
 * shared it out on release. Peppa must not hold other people's money, so a
 * split payment is now transferred straight into each party's own account
 * the moment it is paid, where it stays locked on manual payouts. A release
 * pays each party out from their own balance; nothing waits with Peppa.
 *
 * That means the split has to be known at payment time, so it moves from the
 * rule to the hold. Rules keep deciding when money is released and how much,
 * and may name which parties' shares they release.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing has been opened under the pooled model; the rename keeps
        // the column meaning honest.
        DB::table('settlement_holds')->where('funding', 'pooled')->update(['funding' => 'split']);

        Schema::table('settlement_holds', function (Blueprint $table) {
            // The split as the partner sent it, e.g.
            // [{"to":"freight","percentage":10},{"to":"supplier","remainder":true}]
            $table->json('split')->nullable()->after('parties');
        });

        Schema::table('settlement_rules', function (Blueprint $table) {
            // Whose shares this rule releases; null releases every share.
            $table->json('parties')->nullable()->after('release_percentage');
        });

        Schema::table('settlement_rules', function (Blueprint $table) {
            $table->dropColumn('destinations');
        });

        Schema::create('settlement_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_hold_id');
            $table->string('destination')->comment('role, acct_ id, or platform');
            $table->unsignedBigInteger('connected_account_id')->nullable();
            $table->string('account_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('amount_released', 15, 2)->default(0)->comment('decided, whether or not paid out yet');
            $table->decimal('amount_paid_out', 15, 2)->default(0);
            $table->decimal('amount_refunded', 15, 2)->default(0);
            $table->char('currency', 3);
            $table->timestamps();

            $table->foreign('settlement_hold_id')->references('id')->on('settlement_holds')->onDelete('cascade');
            $table->foreign('connected_account_id')->references('id')->on('connected_accounts')->onDelete('set null');
            $table->index('connected_account_id');
        });

        // Every movement of a share: the transfer into the party's account at
        // payment, payouts on release, reversals on refund.
        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('settlement_share_id')->nullable()->after('settlement_hold_id');
            $table->string('kind')->default('transfer')->after('settlement_share_id')
                ->comment('transfer, payout, reversal');
        });

        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->renameColumn('transfer_id', 'provider_id');
        });

        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->string('release_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->renameColumn('provider_id', 'transfer_id');
        });

        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->dropColumn(['settlement_share_id', 'kind']);
        });

        Schema::dropIfExists('settlement_shares');

        Schema::table('settlement_rules', function (Blueprint $table) {
            $table->json('destinations')->nullable()->after('release_percentage');
            $table->dropColumn('parties');
        });

        Schema::table('settlement_holds', function (Blueprint $table) {
            $table->dropColumn('split');
        });

        DB::table('settlement_holds')->where('funding', 'split')->update(['funding' => 'pooled']);
    }
};
