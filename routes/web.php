<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => 'App\Http\Controllers\Escrow\Backend',
], function () {
    Route::get('/logout', 'LoginController@logout')->name('escrow.logout');
    Route::get('/login', 'LoginController@getLoginPage')->name('escrow.login');
    Route::post('/login', 'LoginController@postLogin')->name('escrow.post.login');
});

Route::prefix('merchant')->name('merchant.')->group(function () {
    Route::get('/register', [\App\Http\Controllers\Merchant\AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [\App\Http\Controllers\Merchant\AuthController::class, 'register'])->name('register.submit');
    Route::get('/login', [\App\Http\Controllers\Merchant\AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [\App\Http\Controllers\Merchant\AuthController::class, 'login'])->name('login');
    Route::get('/logout', [\App\Http\Controllers\Merchant\AuthController::class, 'logout'])->name('logout');
});

Route::prefix('merchant')->name('merchant.')->middleware('merchant.auth')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Merchant\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/api-keys', [\App\Http\Controllers\Merchant\DashboardController::class, 'showApiKeys'])->name('api-keys');
    Route::post('/api-keys/regenerate', [\App\Http\Controllers\Merchant\DashboardController::class, 'regenerateKeys'])->name('regenerate-keys');
    Route::get('/settings', [\App\Http\Controllers\Merchant\DashboardController::class, 'showSettings'])->name('settings');
    Route::post('/settings', [\App\Http\Controllers\Merchant\DashboardController::class, 'updateSettings'])->name('update-settings');
    Route::post('/settings/profile', [\App\Http\Controllers\Merchant\DashboardController::class, 'updateProfile'])->name('update-profile');
    Route::post('/settings/password', [\App\Http\Controllers\Merchant\DashboardController::class, 'updatePassword'])->name('update-password');

    // Bank account management
    Route::get('/bank-accounts', [\App\Http\Controllers\Merchant\DashboardController::class, 'showBankAccounts'])->name('bank-accounts');
    Route::post('/bank-accounts', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeBankAccount'])->name('bank-accounts.store');
    Route::post('/bank-accounts/{id}/default', [\App\Http\Controllers\Merchant\DashboardController::class, 'setDefaultBankAccount'])->name('bank-accounts.default');
    Route::delete('/bank-accounts/{id}', [\App\Http\Controllers\Merchant\DashboardController::class, 'destroyBankAccount'])->name('bank-accounts.destroy');

    // Advanced tenant config
    Route::get('/advanced-settings', [\App\Http\Controllers\Merchant\DashboardController::class, 'showAdvancedSettings'])->name('advanced-settings');
    Route::post('/advanced-settings', [\App\Http\Controllers\Merchant\DashboardController::class, 'updateAdvancedSettings'])->name('update-advanced-settings');

    Route::get('/transactions', [\App\Http\Controllers\Merchant\DashboardController::class, 'transactions'])->name('transactions');

    // Wallet pages
    Route::get('/wallets', [\App\Http\Controllers\Merchant\DashboardController::class, 'wallets'])->name('wallets');
    Route::get('/wallets/{id}/transactions', [\App\Http\Controllers\Merchant\DashboardController::class, 'walletTransactions'])->name('wallet.transactions');

    // Transaction types
    Route::get('/transaction-types', [\App\Http\Controllers\Merchant\DashboardController::class, 'transactionTypes'])->name('transaction-types');
    Route::post('/transaction-types', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeTransactionType'])->name('transaction-types.store');
    Route::post('/transaction-types/seed', [\App\Http\Controllers\Merchant\DashboardController::class, 'seedTransactionTypes'])->name('transaction-types.seed');

    // Commission rules
    Route::get('/commission-rules', [\App\Http\Controllers\Merchant\DashboardController::class, 'commissionRules'])->name('commission-rules');
    Route::post('/commission-rules', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeCommissionRule'])->name('commission-rules.store');
    Route::delete('/commission-rules/{id}', [\App\Http\Controllers\Merchant\DashboardController::class, 'destroyCommissionRule'])->name('commission-rules.destroy');

    // Virtual accounts
    Route::get('/virtual-accounts', [\App\Http\Controllers\Merchant\DashboardController::class, 'virtualAccounts'])->name('virtual-accounts');

    // Subscription plans
    Route::get('/subscription-plans', [\App\Http\Controllers\Merchant\DashboardController::class, 'subscriptionPlans'])->name('subscription-plans');
    Route::post('/subscription-plans', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeSubscriptionPlan'])->name('subscription-plans.store');
    Route::post('/subscription-plans/{id}/toggle', [\App\Http\Controllers\Merchant\DashboardController::class, 'toggleSubscriptionPlan'])->name('subscription-plans.toggle');

    // Subscriptions
    Route::get('/subscriptions', [\App\Http\Controllers\Merchant\DashboardController::class, 'subscriptions'])->name('subscriptions');
    Route::get('/subscriptions/{id}/invoices', [\App\Http\Controllers\Merchant\DashboardController::class, 'subscriptionInvoices'])->name('subscription.invoices');

    // Settlements
    Route::get('/settlements', [\App\Http\Controllers\Merchant\DashboardController::class, 'settlements'])->name('settlements');
    Route::get('/settlements/{id}', [\App\Http\Controllers\Merchant\DashboardController::class, 'settlementDetail'])->name('settlement.detail');

    // Revenue dashboard
    Route::get('/revenue', [\App\Http\Controllers\Merchant\DashboardController::class, 'revenue'])->name('revenue');

    // Reward programs
    Route::get('/reward-programs', [\App\Http\Controllers\Merchant\DashboardController::class, 'rewardPrograms'])->name('reward-programs');
    Route::post('/reward-programs', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeRewardProgram'])->name('reward-programs.store');
    Route::post('/reward-programs/{id}/toggle', [\App\Http\Controllers\Merchant\DashboardController::class, 'toggleRewardProgram'])->name('reward-programs.toggle');

    // Listing fees
    Route::get('/listing-fees', [\App\Http\Controllers\Merchant\DashboardController::class, 'listingFees'])->name('listing-fees');
    Route::post('/listing-fees', [\App\Http\Controllers\Merchant\DashboardController::class, 'storeListingFee'])->name('listing-fees.store');
    Route::post('/listing-fees/{id}/toggle', [\App\Http\Controllers\Merchant\DashboardController::class, 'toggleListingFee'])->name('listing-fees.toggle');

    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\Merchant\DashboardController::class, 'notifications'])->name('notifications');
});

Route::group([
    'namespace' => 'App\Http\Controllers\Escrow\Backend',
    'middleware' => 'backend.auth',
], function () {
    Route::get('/', 'BackendController@getDashboard')->name('escrow.dashboard');
    Route::get('/dashboard', 'BackendController@getDashboard')->name('escrow.dashboard');

    Route::get('/users', 'LoginController@getUsers')->name('escrow.users');
    Route::post('/users/register', 'LoginController@addUser')->name('escrow.user.save');
    Route::post('/users/edit', 'LoginController@editUser')->name('escrow.user.edit');
    Route::post('/users/delete', 'LoginController@deleteUser')->name('escrow.user.delete');
    Route::post('/users/block', 'LoginController@blockUser')->name('escrow.user.block');

    Route::get('/Reports', 'BackendController@getReportPage')->name('escrow.reports');
});

Route::prefix('admin')->name('admin.')->middleware('admin.auth')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [\App\Http\Controllers\Admin\AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/merchants', [\App\Http\Controllers\Admin\AdminController::class, 'merchants'])->name('merchants');
    Route::get('/merchants/{id}', [\App\Http\Controllers\Admin\AdminController::class, 'merchantDetail'])->name('merchant.detail');
    Route::post('/merchants/{id}/toggle', [\App\Http\Controllers\Admin\AdminController::class, 'merchantToggleStatus'])->name('merchant.toggle');
    Route::get('/transactions', [\App\Http\Controllers\Admin\AdminController::class, 'transactions'])->name('transactions');
    Route::get('/transactions/{id}', [\App\Http\Controllers\Admin\AdminController::class, 'transactionDetail'])->name('transaction.detail');
    Route::get('/settlements', [\App\Http\Controllers\Admin\AdminController::class, 'settlements'])->name('settlements');
    Route::get('/settlements/{id}', [\App\Http\Controllers\Admin\AdminController::class, 'settlementDetail'])->name('settlement.detail');
    Route::get('/notifications', [\App\Http\Controllers\Admin\AdminController::class, 'notificationLogs'])->name('notifications');
    Route::get('/revenue', [\App\Http\Controllers\Admin\AdminController::class, 'revenue'])->name('revenue');
});
