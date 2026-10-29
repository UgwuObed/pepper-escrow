<?php

use Illuminate\Support\Facades\Route;

// Public Escrow API endpoints (authenticated by API key)
Route::group([
    'namespace' => 'App\Http\Controllers\Escrow',
    'prefix' => 'escrow',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('BankCodes', 'EscrowController@getBankCodes');

    // Transaction endpoints
    Route::post('Transaction/create', 'EscrowController@createTransaction');
    Route::post('Transaction/create_card', 'EscrowController@createCardTransaction');
    Route::get('Transaction/getPaymentLink', 'EscrowController@getPaymentLink');
    Route::post('Transaction/stop', 'EscrowController@stopTransaction');
    Route::post('Transaction/release', 'EscrowController@releaseTransaction');
    Route::post('Transaction/reqExtension', 'EscrowController@reqTransactionExtension');
    Route::post('Transaction/extend', 'EscrowController@extendTransaction');
    Route::post('Transaction/reqRefund', 'EscrowController@reqTransactionRefund');
    Route::post('Transaction/refund', 'EscrowController@refundTransaction');
    Route::get('Transaction/AppTranx', 'EscrowController@getAppTransactions');
    Route::get('Transaction/AppTranx/ByRef', 'EscrowController@getAppTransactionByRef');
    Route::get('Transaction/verify', 'EscrowController@verifyPayment');

    // Dispute endpoints
    Route::post('Dispute/reportDispute', 'EscrowController@reportDispute');
    Route::post('Dispute/updateDispute', 'EscrowController@updateDispute');
    Route::post('Dispute/resolveDispute', 'EscrowController@resolveDispute');
    Route::post('Dispute/reportDisputeHearing', 'EscrowController@reportDisputeHearing');
    Route::get('Dispute/getDispute', 'EscrowController@getDispute');
    Route::get('Dispute/getAllDispute', 'EscrowController@getAllDispute');
});

// Public card payment webhook (no API key — gateways call this, and they cannot
// send one. Authenticity is established by signature verification in the handler.)
Route::group([
    'namespace' => 'App\Http\Controllers\Escrow',
    'prefix' => 'escrow',
], function () {
    Route::post('webhook/{gateway}', 'EscrowController@handleWebhook');
});

// Client Configuration API (authenticated by API key)
Route::group([
    'prefix' => 'client',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('config', [\App\Http\Controllers\Client\ClientSettingsController::class, 'getConfig']);
    Route::put('config', [\App\Http\Controllers\Client\ClientSettingsController::class, 'updateConfig']);

    Route::get('bank-accounts', [\App\Http\Controllers\Client\ClientSettingsController::class, 'listBankAccounts']);
    Route::post('bank-accounts', [\App\Http\Controllers\Client\ClientSettingsController::class, 'createBankAccount']);
    Route::put('bank-accounts/{id}', [\App\Http\Controllers\Client\ClientSettingsController::class, 'updateBankAccount']);
    Route::delete('bank-accounts/{id}', [\App\Http\Controllers\Client\ClientSettingsController::class, 'deleteBankAccount']);
    Route::post('bank-accounts/{id}/default', [\App\Http\Controllers\Client\ClientSettingsController::class, 'setDefaultBankAccount']);

    Route::get('api-token-config', [\App\Http\Controllers\Client\ClientSettingsController::class, 'getApiTokenConfig']);
    Route::put('api-token-config', [\App\Http\Controllers\Client\ClientSettingsController::class, 'updateApiTokenConfig']);
});

// Wallet API (authenticated by API key)
Route::group([
    'prefix' => 'client/wallets',
    'middleware' => 'auth.api-key',
], function () {
    Route::post('/', [\App\Http\Controllers\Client\WalletController::class, 'create']);
    Route::get('/', [\App\Http\Controllers\Client\WalletController::class, 'list']);
    Route::get('find-by-user', [\App\Http\Controllers\Client\WalletController::class, 'findByUser']);
    Route::get('{id}', [\App\Http\Controllers\Client\WalletController::class, 'show']);
    Route::get('{id}/balance', [\App\Http\Controllers\Client\WalletController::class, 'getBalance']);
    Route::get('{id}/transactions', [\App\Http\Controllers\Client\WalletController::class, 'transactions']);
    Route::post('{id}/credit', [\App\Http\Controllers\Client\WalletController::class, 'credit']);
    Route::post('{id}/debit', [\App\Http\Controllers\Client\WalletController::class, 'debit']);
    Route::post('transfer', [\App\Http\Controllers\Client\WalletController::class, 'transfer']);
});

// Transaction Types API (authenticated by API key)
Route::group([
    'prefix' => 'client/transaction-types',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\TransactionTypeController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\TransactionTypeController::class, 'create']);
    Route::post('seed-defaults', [\App\Http\Controllers\Client\TransactionTypeController::class, 'seedDefaults']);
    Route::get('{id}', [\App\Http\Controllers\Client\TransactionTypeController::class, 'show']);
    Route::put('{id}', [\App\Http\Controllers\Client\TransactionTypeController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\TransactionTypeController::class, 'destroy']);
});

// Commission Rules API (authenticated by API key)
Route::group([
    'prefix' => 'client/commission-rules',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\CommissionRuleController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\CommissionRuleController::class, 'create']);
    Route::post('calculate', [\App\Http\Controllers\Client\CommissionRuleController::class, 'calculate']);
    Route::get('{id}', [\App\Http\Controllers\Client\CommissionRuleController::class, 'show']);
    Route::put('{id}', [\App\Http\Controllers\Client\CommissionRuleController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\CommissionRuleController::class, 'destroy']);
});

// Seller connected accounts for conditional settlement (authenticated by API key)
Route::group([
    'prefix' => 'connected-accounts',
    'middleware' => 'auth.api-key',
], function () {
    Route::post('/', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'create']);
    Route::get('/', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'list']);
    Route::get('{id}', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'show']);
    Route::post('{id}/onboarding-link', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'onboardingLink']);
    Route::post('{id}/sync', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'sync']);

    // The seller's own view. They have no Stripe dashboard, so this is the
    // only place they can see their balance, payouts and held funds.
    Route::get('{id}/dashboard', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'dashboard']);
    Route::post('{id}/account-session', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'accountSession']);
});

// Public Stripe Connect webhook (no API key — Stripe calls this, so the
// signature is the only thing establishing who sent it)
Route::post('stripe/connect/webhook', [\App\Http\Controllers\Client\ConnectedAccountController::class, 'webhook']);

// Held payments and the conditions that release them (authenticated by API key).
// Addressed by the partner's own reference rather than our row id.
Route::group([
    'prefix' => 'holds',
    'middleware' => 'auth.api-key',
], function () {
    Route::post('/', [\App\Http\Controllers\Client\SettlementHoldController::class, 'store']);
    Route::get('/', [\App\Http\Controllers\Client\SettlementHoldController::class, 'index']);
    Route::get('{reference}', [\App\Http\Controllers\Client\SettlementHoldController::class, 'show']);
    Route::post('{reference}/confirm', [\App\Http\Controllers\Client\SettlementHoldController::class, 'confirm']);
    Route::post('{reference}/approve', [\App\Http\Controllers\Client\SettlementHoldController::class, 'approve']);
    Route::post('{reference}/milestones', [\App\Http\Controllers\Client\SettlementHoldController::class, 'milestone']);
    Route::post('{reference}/transfers/retry', [\App\Http\Controllers\Client\SettlementHoldController::class, 'retryTransfers']);
    Route::post('{reference}/dispute', [\App\Http\Controllers\Client\SettlementHoldController::class, 'dispute']);
    Route::post('{reference}/dispute/resolve', [\App\Http\Controllers\Client\SettlementHoldController::class, 'resolveDispute']);
    Route::post('{reference}/refund', [\App\Http\Controllers\Client\SettlementHoldController::class, 'refund']);
});

// Partner-configured release rules (authenticated by API key)
Route::group([
    'prefix' => 'settlement-rules',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\SettlementRuleController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Client\SettlementRuleController::class, 'store']);
    Route::put('{id}', [\App\Http\Controllers\Client\SettlementRuleController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\SettlementRuleController::class, 'destroy']);
});

// Bank Transfer / Virtual Accounts API (authenticated by API key)
Route::group([
    'prefix' => 'client/bank-transfer',
    'middleware' => 'auth.api-key',
], function () {
    Route::post('assign', [\App\Http\Controllers\Client\BankTransferController::class, 'assign']);
    Route::get('accounts', [\App\Http\Controllers\Client\BankTransferController::class, 'list']);
    Route::get('accounts/{id}', [\App\Http\Controllers\Client\BankTransferController::class, 'info']);
    Route::post('accounts/{id}/deactivate', [\App\Http\Controllers\Client\BankTransferController::class, 'deactivate']);
});

// Public bank transfer webhook (no API key — gateways call this)
Route::group([
    'prefix' => 'bank-transfer',
], function () {
    Route::post('webhook/{gateway}', [\App\Http\Controllers\Client\BankTransferController::class, 'webhook']);
});

// Subscription Plans API (authenticated by API key)
Route::group([
    'prefix' => 'client/subscription-plans',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\SubscriptionPlanController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\SubscriptionPlanController::class, 'create']);
    Route::get('{id}', [\App\Http\Controllers\Client\SubscriptionPlanController::class, 'show']);
    Route::put('{id}', [\App\Http\Controllers\Client\SubscriptionPlanController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\SubscriptionPlanController::class, 'destroy']);
});

// Subscriptions API (authenticated by API key)
Route::group([
    'prefix' => 'client/subscriptions',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\SubscriptionController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\SubscriptionController::class, 'subscribe']);
    Route::get('{id}', [\App\Http\Controllers\Client\SubscriptionController::class, 'show']);
    Route::post('{id}/cancel', [\App\Http\Controllers\Client\SubscriptionController::class, 'cancel']);
    Route::get('{id}/invoices', [\App\Http\Controllers\Client\SubscriptionController::class, 'invoices']);
    Route::post('{id}/bill-now', [\App\Http\Controllers\Client\SubscriptionController::class, 'billNow']);
});

// Settlements API (authenticated by API key)
Route::group([
    'prefix' => 'client/settlements',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\SettlementController::class, 'list']);
    Route::get('preview', [\App\Http\Controllers\Client\SettlementController::class, 'preview']);
    Route::post('/', [\App\Http\Controllers\Client\SettlementController::class, 'create']);
    Route::get('{id}', [\App\Http\Controllers\Client\SettlementController::class, 'show']);
    Route::post('{id}/process', [\App\Http\Controllers\Client\SettlementController::class, 'process']);
});

// Public settlement webhook (no API key — gateways call this)
Route::group([
    'prefix' => 'settlements',
], function () {
    Route::post('webhook/{gateway}', [\App\Http\Controllers\Client\SettlementController::class, 'webhook']);
});

// Revenue & Commission Analytics API (authenticated by API key)
Route::group([
    'prefix' => 'revenue',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('overview', [\App\Http\Controllers\Api\RevenueController::class, 'overview']);
    Route::get('by-type', [\App\Http\Controllers\Api\RevenueController::class, 'byTransactionType']);
    Route::get('trend', [\App\Http\Controllers\Api\RevenueController::class, 'trend']);
    Route::get('mrr', [\App\Http\Controllers\Api\RevenueController::class, 'mrr']);
    Route::get('merchants', [\App\Http\Controllers\Api\RevenueController::class, 'merchantBreakdown']);
});

// Rewards API (authenticated by API key)
Route::group([
    'prefix' => 'client/rewards',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('programs', [\App\Http\Controllers\Client\RewardController::class, 'listPrograms']);
    Route::post('programs', [\App\Http\Controllers\Client\RewardController::class, 'createProgram']);
    Route::put('programs/{id}', [\App\Http\Controllers\Client\RewardController::class, 'updateProgram']);
    Route::delete('programs/{id}', [\App\Http\Controllers\Client\RewardController::class, 'deleteProgram']);
    Route::get('balance', [\App\Http\Controllers\Client\RewardController::class, 'getBalance']);
    Route::get('history', [\App\Http\Controllers\Client\RewardController::class, 'customerHistory']);
    Route::post('award', [\App\Http\Controllers\Client\RewardController::class, 'awardManual']);
    Route::post('redeem', [\App\Http\Controllers\Client\RewardController::class, 'redeem']);
});

// Listing Fees API (authenticated by API key)
Route::group([
    'prefix' => 'client/listing-fees',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\ListingFeeController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\ListingFeeController::class, 'create']);
    Route::put('{id}', [\App\Http\Controllers\Client\ListingFeeController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\ListingFeeController::class, 'delete']);
    Route::post('calculate', [\App\Http\Controllers\Client\ListingFeeController::class, 'calculate']);
});

// Notifications API (authenticated by API key)
Route::group([
    'prefix' => 'client/notifications',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('logs', [\App\Http\Controllers\Client\NotificationController::class, 'logs']);
    Route::get('events', [\App\Http\Controllers\Client\NotificationController::class, 'events']);
    Route::post('test-webhook', [\App\Http\Controllers\Client\NotificationController::class, 'testWebhook']);
});

// Rewards API (authenticated by API key)
Route::group([
    'prefix' => 'client/rewards',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('programs', [\App\Http\Controllers\Client\RewardController::class, 'listPrograms']);
    Route::post('programs', [\App\Http\Controllers\Client\RewardController::class, 'createProgram']);
    Route::put('programs/{id}', [\App\Http\Controllers\Client\RewardController::class, 'updateProgram']);
    Route::delete('programs/{id}', [\App\Http\Controllers\Client\RewardController::class, 'deleteProgram']);
    Route::get('balance', [\App\Http\Controllers\Client\RewardController::class, 'getBalance']);
    Route::get('history', [\App\Http\Controllers\Client\RewardController::class, 'customerHistory']);
    Route::post('award', [\App\Http\Controllers\Client\RewardController::class, 'awardManual']);
    Route::post('redeem', [\App\Http\Controllers\Client\RewardController::class, 'redeem']);
});

// Listing Fees API (authenticated by API key)
Route::group([
    'prefix' => 'client/listing-fees',
    'middleware' => 'auth.api-key',
], function () {
    Route::get('/', [\App\Http\Controllers\Client\ListingFeeController::class, 'list']);
    Route::post('/', [\App\Http\Controllers\Client\ListingFeeController::class, 'create']);
    Route::put('{id}', [\App\Http\Controllers\Client\ListingFeeController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Client\ListingFeeController::class, 'delete']);
    Route::post('calculate', [\App\Http\Controllers\Client\ListingFeeController::class, 'calculate']);
});
