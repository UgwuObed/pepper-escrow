<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\ListingFee;
use App\Models\Merchant;
use App\Models\MerchantBankAccount;
use App\Models\NotificationLog;
use App\Models\RewardBalance;
use App\Models\RewardProgram;
use App\Models\RewardTransaction;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\VirtualAccount;
use App\Models\Wallet;
use App\Services\CommissionService;
use App\Services\TenantService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    protected TenantService $tenantService;
    protected WalletService $walletService;
    protected CommissionService $commissionService;

    public function __construct(TenantService $tenantService, WalletService $walletService, CommissionService $commissionService)
    {
        $this->tenantService = $tenantService;
        $this->walletService = $walletService;
        $this->commissionService = $commissionService;
    }

    public function index(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $apiToken = $merchant->apiToken;

        $stats = [
            'total_transactions' => Transaction::where('appid', $merchant->id)->count(),
            'released'           => Transaction::where('appid', $merchant->id)->where('trans_status', 'Released')->count(),
            'open'               => Transaction::where('appid', $merchant->id)->where('trans_status', 'Open')->count(),
            'disputed'           => Transaction::where('appid', $merchant->id)->where('trans_status', 'Disputed')->count(),
        ];

        $recentTransactions = Transaction::where('appid', $merchant->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('merchant.dashboard', compact('merchant', 'apiToken', 'stats', 'recentTransactions'));
    }

    public function showApiKeys(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $apiToken = $merchant->apiToken;

        return view('merchant.api_keys', compact('merchant', 'apiToken'));
    }

    public function regenerateKeys(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $apiToken = $merchant->apiToken;

        $request->validate(['password' => 'required|string']);

        if (!Hash::check($request->password, $merchant->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $apiToken->update([
            'api_key'    => 'PEP_' . Str::random(32),
            'api_secret' => Str::random(64),
        ]);

        return back()->with('success', 'API keys regenerated successfully.');
    }

    public function showSettings(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $apiToken = $merchant->apiToken;
        $clientConfig = $this->tenantService->getClientConfig($merchant);

        return view('merchant.settings', compact('merchant', 'apiToken', 'clientConfig'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $apiToken = $merchant->apiToken;

        $validated = $request->validate([
            'webhook_url'     => 'nullable|url|max:500',
            'payment_gateway' => 'required|in:paystack,stripe,seerbit,flutterwave',
            'gateway_config'  => 'nullable|json',
            'webhook_secret'  => 'nullable|string|max:255',
        ]);

        $merchant->update(['webhook_url' => $validated['webhook_url'] ?? null]);

        $apiToken->update([
            'payment_gateway' => $validated['payment_gateway'],
            'gateway_config'  => $validated['gateway_config'] ? json_decode($validated['gateway_config'], true) : [],
        ]);

        $this->tenantService->updateClientConfig($merchant, [
            'webhook_secret' => $validated['webhook_secret'] ?? null,
            'webhook_url' => $validated['webhook_url'] ?? null,
        ]);

        return back()->with('success', 'Settings updated successfully.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'website'       => 'nullable|url|max:255',
            'email'         => 'required|email|unique:merchants,email,' . $merchant->id,
        ]);

        $merchant->update($validated);

        return back()->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $merchant->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $merchant->update(['password' => Hash::make($validated['password'])]);

        return back()->with('success', 'Password updated successfully.');
    }

    // ─── Bank Account Management ──────────────────────────────────────

    public function showBankAccounts(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $bankAccounts = $merchant->bankAccounts()->orderBy('is_default', 'desc')->get();

        return view('merchant.bank_accounts', compact('merchant', 'bankAccounts'));
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'bank_name'      => 'required|string|max:255',
            'bank_code'      => 'required|string|max:50',
            'account_number' => 'required|string|max:20',
            'account_name'   => 'required|string|max:255',
            'currency'       => 'nullable|string|size:3',
            'is_default'     => 'nullable|boolean',
        ]);

        if ($request->boolean('is_default')) {
            $merchant->bankAccounts()->update(['is_default' => false]);
        }

        $merchant->bankAccounts()->create($validated);

        return back()->with('success', 'Bank account added successfully.');
    }

    public function setDefaultBankAccount(Request $request, int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $account = $merchant->bankAccounts()->findOrFail($id);

        $merchant->bankAccounts()->update(['is_default' => false]);
        $account->update(['is_default' => true]);

        return back()->with('success', 'Default bank account updated.');
    }

    public function destroyBankAccount(Request $request, int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $account = $merchant->bankAccounts()->findOrFail($id);
        $account->delete();

        return back()->with('success', 'Bank account removed.');
    }

    // ─── Advanced Tenant Settings ─────────────────────────────────────

    public function showAdvancedSettings(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $clientConfig = $this->tenantService->getClientConfig($merchant);

        return view('merchant.advanced_settings', compact('merchant', 'clientConfig'));
    }

    public function updateAdvancedSettings(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'escrow_hold_days'                => 'nullable|integer|min:1|max:365',
            'settlement_schedule'             => 'nullable|in:manual,daily,weekly,monthly',
            'settlement_day'                  => 'nullable|integer|min:1|max:31',
            'min_settlement_amount'           => 'nullable|numeric|min:0',
            'auto_release_enabled'            => 'nullable|boolean',
            'require_fulfillment_confirmation' => 'nullable|boolean',
            'notification_settings'           => 'nullable|json',
            'allowed_transaction_types'       => 'nullable|array',
        ]);

        $update = [];

        if ($request->filled('escrow_hold_days')) {
            $update['escrow_hold_days'] = $validated['escrow_hold_days'];
        }
        if ($request->filled('settlement_schedule')) {
            $update['settlement_schedule'] = $validated['settlement_schedule'];
        }
        if ($request->filled('settlement_day')) {
            $update['settlement_day'] = $validated['settlement_day'];
        }
        if ($request->filled('min_settlement_amount')) {
            $update['min_settlement_amount'] = $validated['min_settlement_amount'];
        }
        $update['auto_release_enabled'] = $request->boolean('auto_release_enabled');
        $update['require_fulfillment_confirmation'] = $request->boolean('require_fulfillment_confirmation');

        if ($request->filled('notification_settings')) {
            $update['notification_settings'] = json_decode($validated['notification_settings'], true);
        }
        if ($request->filled('allowed_transaction_types')) {
            $update['allowed_transaction_types'] = $validated['allowed_transaction_types'];
        }

        $this->tenantService->updateClientConfig($merchant, $update);

        return back()->with('success', 'Advanced settings updated successfully.');
    }

    public function transactions(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $transactions = Transaction::where('appid', $merchant->id)
            ->orderByDesc('id')
            ->paginate(20);

        return view('merchant.transactions', compact('merchant', 'transactions'));
    }

    // ─── Wallet Management ────────────────────────────────────────────

    public function wallets(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $wallets = Wallet::byMerchant($merchant->id)->orderBy('created_at', 'desc')->get();

        $summary = [
            'total_balance' => $wallets->sum(fn($w) => (float) $w->balance),
            'total_ledger' => $wallets->sum(fn($w) => (float) $w->ledger_balance),
            'total_hold' => $wallets->sum(fn($w) => (float) $w->hold_balance),
            'wallet_count' => $wallets->count(),
        ];

        return view('merchant.wallets', compact('merchant', 'wallets', 'summary'));
    }

    public function walletTransactions(int $id): View
    {
        $merchant = Auth::guard('merchant')->user();
        $wallet = Wallet::byMerchant($merchant->id)->findOrFail($id);
        $transactions = $wallet->transactions()->orderBy('created_at', 'desc')->paginate(30);

        return view('merchant.wallet_transactions', compact('merchant', 'wallet', 'transactions'));
    }

    // ─── Transaction Types ────────────────────────────────────────────

    public function transactionTypes(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $types = TransactionType::where('merchant_id', $merchant->id)->orderBy('name')->get();

        return view('merchant.transaction_types', compact('merchant', 'types'));
    }

    public function storeTransactionType(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'supports_escrow' => 'nullable|boolean',
            'requires_fulfillment' => 'nullable|boolean',
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['name']);

        if (TransactionType::where('merchant_id', $merchant->id)->where('slug', $slug)->exists()) {
            return back()->withErrors(['name' => 'A transaction type with this name already exists.']);
        }

        TransactionType::create([
            'merchant_id' => $merchant->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'supports_escrow' => $request->boolean('supports_escrow', true),
            'requires_fulfillment' => $request->boolean('requires_fulfillment', true),
        ]);

        $this->commissionService->seedDefaults($merchant);

        return back()->with('success', 'Transaction type created.');
    }

    public function seedTransactionTypes(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        if (TransactionType::where('merchant_id', $merchant->id)->exists()) {
            return back()->withErrors(['name' => 'Transaction types already exist. Remove them first to reseed.']);
        }

        foreach (TransactionType::getDefaults($merchant->id) as $default) {
            TransactionType::create($default);
        }

        $this->commissionService->seedDefaults($merchant);

        return back()->with('success', 'Default transaction types seeded.');
    }

    // ─── Commission Rules ─────────────────────────────────────────────

    public function commissionRules(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $rules = CommissionRule::where('merchant_id', $merchant->id)
            ->with('transactionType')
            ->orderBy('priority', 'desc')
            ->get();
        $types = TransactionType::where('merchant_id', $merchant->id)->active()->get();

        return view('merchant.commission_rules', compact('merchant', 'rules', 'types'));
    }

    public function storeCommissionRule(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'transaction_type_id' => 'required|integer|exists:transaction_types,id',
            'name' => 'nullable|string|max:255',
            'rate_type' => 'required|in:percentage,flat',
            'rate_value' => 'required|numeric|min:0',
            'cap_amount' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'payer' => 'nullable|in:merchant,customer',
        ]);

        CommissionRule::create(array_merge($validated, ['merchant_id' => $merchant->id]));

        return back()->with('success', 'Commission rule created.');
    }

    public function destroyCommissionRule(int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $rule = CommissionRule::where('merchant_id', $merchant->id)->findOrFail($id);
        $rule->delete();

        return back()->with('success', 'Commission rule deleted.');
    }

    // ─── Virtual Accounts ─────────────────────────────────────────────

    public function virtualAccounts(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $accounts = VirtualAccount::where('merchant_id', $merchant->id)
            ->with('transaction')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return view('merchant.virtual_accounts', compact('merchant', 'accounts'));
    }

    // ─── Subscription Plans ───────────────────────────────────────────

    public function subscriptionPlans(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $plans = SubscriptionPlan::where('merchant_id', $merchant->id)
            ->with('subscriptions')
            ->orderBy('name')
            ->get();
        $types = TransactionType::where('merchant_id', $merchant->id)->active()->get();

        return view('merchant.subscription_plans', compact('merchant', 'plans', 'types'));
    }

    public function storeSubscriptionPlan(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'billing_cycle' => 'required|in:daily,weekly,monthly,yearly',
            'cycle_interval' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:0',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
        ]);

        SubscriptionPlan::create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $validated['transaction_type_id'] ?? null,
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']) . '-' . \Illuminate\Support\Str::random(6),
            'description' => $validated['description'] ?? null,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'] ?? 'NGN',
            'billing_cycle' => $validated['billing_cycle'],
            'cycle_interval' => $validated['cycle_interval'] ?? 1,
            'trial_days' => $validated['trial_days'] ?? 0,
        ]);

        return back()->with('success', 'Subscription plan created.');
    }

    public function toggleSubscriptionPlan(int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $plan = SubscriptionPlan::where('merchant_id', $merchant->id)->findOrFail($id);
        $plan->update(['is_active' => !$plan->is_active]);

        return back()->with('success', $plan->is_active ? 'Plan activated.' : 'Plan deactivated.');
    }

    // ─── Subscriptions ────────────────────────────────────────────────

    public function subscriptions(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $subscriptions = Subscription::where('merchant_id', $merchant->id)
            ->with('plan')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return view('merchant.subscriptions', compact('merchant', 'subscriptions'));
    }

    public function subscriptionInvoices(int $id): View
    {
        $merchant = Auth::guard('merchant')->user();
        $subscription = Subscription::where('merchant_id', $merchant->id)
            ->with('plan')
            ->findOrFail($id);
        $invoices = $subscription->invoices()
            ->with('transaction')
            ->orderBy('billing_period', 'desc')
            ->paginate(30);

        return view('merchant.subscription_invoices', compact('merchant', 'subscription', 'invoices'));
    }

    // ─── Settlements ──────────────────────────────────────────────────

    public function settlements(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $settlements = Settlement::where('merchant_id', $merchant->id)
            ->withCount('items')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return view('merchant.settlements', compact('merchant', 'settlements'));
    }

    public function settlementDetail(int $id): View
    {
        $merchant = Auth::guard('merchant')->user();
        $settlement = Settlement::where('merchant_id', $merchant->id)
            ->with('items.transaction')
            ->findOrFail($id);

        return view('merchant.settlement_detail', compact('merchant', 'settlement'));
    }

    // ─── Revenue Dashboard ────────────────────────────────────────────

    public function revenue(): View
    {
        $merchant = Auth::guard('merchant')->user();

        $monthlyData = Transaction::where('appid', $merchant->id)
            ->where('payment_status', 'Paid')
            ->select(
                DB::raw("DATE_FORMAT(payment_date, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as volume'),
                DB::raw('SUM(commission_amount) as commission'),
            )
            ->whereNotNull('payment_date')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $typeBreakdown = Transaction::where('appid', $merchant->id)
            ->where('payment_status', 'Paid')
            ->whereNotNull('transaction_type_id')
            ->select(
                'transaction_type_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(commission_amount) as commission'),
            )
            ->groupBy('transaction_type_id')
            ->get()
            ->map(function ($row) {
                $type = TransactionType::find($row->transaction_type_id);
                return [
                    'name' => $type?->name ?? 'Unknown',
                    'count' => $row->count,
                    'commission' => (float) ($row->commission ?? 0),
                ];
            })->sortByDesc('commission');

        $settlementStats = Settlement::where('merchant_id', $merchant->id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw('SUM(net_amount) as total_paid'),
            )->first();

        return view('merchant.revenue', compact(
            'merchant', 'monthlyData', 'typeBreakdown', 'settlementStats'
        ));
    }

    // ─── Reward Programs (Web) ──────────────────────────────────────

    public function rewardPrograms(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $programs = RewardProgram::where('merchant_id', $merchant->id)->orderBy('name')->get();

        return view('merchant.reward_programs', compact('merchant', 'programs'));
    }

    public function storeRewardProgram(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'reward_type' => 'required|in:points,cashback,discount_percentage,discount_flat',
            'reward_value' => 'required|numeric|min:0',
            'min_transaction_amount' => 'nullable|numeric|min:0',
        ]);

        RewardProgram::create([
            'merchant_id' => $merchant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'reward_type' => $validated['reward_type'],
            'reward_value' => $validated['reward_value'],
            'min_transaction_amount' => $validated['min_transaction_amount'] ?? null,
        ]);

        return back()->with('success', 'Reward program created.');
    }

    public function toggleRewardProgram(int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $program = RewardProgram::where('merchant_id', $merchant->id)->findOrFail($id);
        $program->update(['is_active' => !$program->is_active]);

        return back()->with('success', $program->is_active ? 'Program activated.' : 'Program deactivated.');
    }

    // ─── Listing Fees (Web) ─────────────────────────────────────────

    public function listingFees(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $fees = ListingFee::where('merchant_id', $merchant->id)->with('transactionType')->orderBy('name')->get();
        $types = TransactionType::where('merchant_id', $merchant->id)->active()->get();

        return view('merchant.listing_fees', compact('merchant', 'fees', 'types'));
    }

    public function storeListingFee(Request $request): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fee_type' => 'required|in:flat,percentage',
            'fee_value' => 'required|numeric|min:0',
            'cap_amount' => 'nullable|numeric|min:0',
            'transaction_type_id' => 'nullable|integer|exists:transaction_types,id',
        ]);

        ListingFee::create([
            'merchant_id' => $merchant->id,
            'transaction_type_id' => $validated['transaction_type_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'fee_type' => $validated['fee_type'],
            'fee_value' => $validated['fee_value'],
            'cap_amount' => $validated['cap_amount'] ?? null,
        ]);

        return back()->with('success', 'Listing fee created.');
    }

    public function toggleListingFee(int $id): RedirectResponse
    {
        $merchant = Auth::guard('merchant')->user();
        $fee = ListingFee::where('merchant_id', $merchant->id)->findOrFail($id);
        $fee->update(['is_active' => !$fee->is_active]);

        return back()->with('success', $fee->is_active ? 'Fee activated.' : 'Fee deactivated.');
    }

    // ─── Notifications (Web) ──────────────────────────────────────────

    public function notifications(): View
    {
        $merchant = Auth::guard('merchant')->user();
        $logs = NotificationLog::byMerchant($merchant->id)
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return view('merchant.notifications', compact('merchant', 'logs'));
    }
}
