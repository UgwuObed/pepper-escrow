<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A public link a partner can hand to their sellers.
 *
 * The token identifies the partner without exposing an API key, so the page
 * can be put in an email or a help article. It is off by default: a public
 * page that creates Stripe accounts is an abuse surface, so a partner opts
 * in rather than discovering one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('seller_onboarding_token', 40)->nullable()->unique()->after('status');
            $table->boolean('seller_onboarding_enabled')->default(false)->after('seller_onboarding_token');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['seller_onboarding_token', 'seller_onboarding_enabled']);
        });
    }
};
