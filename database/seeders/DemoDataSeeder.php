<?php

namespace Database\Seeders;

use App\Models\ApiToken;
use App\Models\AppAccount;
use App\Models\ClientConfig;
use App\Models\CommissionRule;
use App\Models\Customer;
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
use App\Models\TransactionHistory;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Merchant::count() >= 3) {
            return;
        }

        // ── 1. Super Admin ──
        User::firstOrCreate(
            ['email' => 'admin@pepperescrow.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'firstName' => 'Super',
                'lastName' => 'Admin',
                'phoneNo' => '+2348000000000',
                'job_title' => 'System Administrator',
                'account_type' => 'admin',
                'status' => true,
                'super_admin' => true,
            ]
        );

        // ── 2. Merchants ──
        $merchantsData = [
            [
                'business_name' => 'Acme Corporation',
                'email' => 'merchant@acme.com',
                'phone' => '+2348012345678',
                'website' => 'https://acme.com',
                'webhook_url' => 'https://acme.com/webhooks/pepperescrow',
                'currency' => 'NGN',
                'gateway' => 'paystack',
                'seed_types' => true,
            ],
            [
                'business_name' => 'Globex Trading Co.',
                'email' => 'merchant@globex.com',
                'phone' => '+2348098765432',
                'website' => 'https://globex.io',
                'webhook_url' => 'https://globex.io/webhooks/pepperescrow',
                'currency' => 'NGN',
                'gateway' => 'stripe',
                'seed_types' => true,
            ],
            [
                'business_name' => 'Initech Solutions',
                'email' => 'merchant@initech.com',
                'phone' => '+2348055551234',
                'website' => 'https://initech.com',
                'webhook_url' => 'https://initech.com/webhooks/pepperescrow',
                'currency' => 'USD',
                'gateway' => 'flutterwave',
                'seed_types' => true,
            ],
        ];

        foreach ($merchantsData as $i => $mData) {
            $merchant = Merchant::firstOrCreate(
                ['email' => $mData['email']],
                [
                    'business_name' => $mData['business_name'],
                    'password' => Hash::make('password'),
                    'phone' => $mData['phone'],
                    'website' => $mData['website'],
                    'webhook_url' => $mData['webhook_url'],
                    'status' => 'active',
                ]
            );

            // API Token
            ApiToken::firstOrCreate(
                ['app_id' => $merchant->id],
                [
                    'merchant_id' => $merchant->id,
                    'api_key' => 'ESCROW_' . strtoupper(Str::random(32)),
                    'api_secret' => Str::random(48),
                    'status' => true,
                    'payment_gateway' => $mData['gateway'],
                ]
            );

            // Customer record for merchant (needed by EscrowController)
            Customer::firstOrCreate(
                ['email' => $merchant->email],
                [
                    'name' => $mData['business_name'],
                    'businessname' => $mData['business_name'],
                    'phone' => $mData['phone'],
                    'usertype' => 'merchant',
                    'merchantid' => (string) $merchant->id,
                    'country' => 'Nigeria',
                ]
            );

            // Client config
            ClientConfig::firstOrCreate(
                ['merchant_id' => $merchant->id],
                [
                    'escrow_hold_days' => 7,
                    'settlement_schedule' => 'manual',
                    'min_settlement_amount' => 1000,
                    'auto_release_enabled' => false,
                    'require_fulfillment_confirmation' => true,
                ]
            );

            // Merchant bank account
            MerchantBankAccount::firstOrCreate(
                ['merchant_id' => $merchant->id, 'is_default' => true],
                [
                    'bank_name' => 'Guaranty Trust Bank',
                    'account_name' => $mData['business_name'],
                    'account_number' => '012345' . str_pad((string) $merchant->id, 4, '0', STR_PAD_LEFT),
                    'bank_code' => '058',
                    'currency' => $mData['currency'],
                    'is_default' => true,
                    'status' => true,
                ]
            );

            // Transaction types
            if ($mData['seed_types']) {
                $defaults = TransactionType::getDefaults($merchant->id);
                foreach ($defaults as $def) {
                    TransactionType::firstOrCreate(
                        ['merchant_id' => $merchant->id, 'slug' => $def['slug']],
                        $def
                    );
                }
            }

            // Commission rule for each transaction type
            $types = TransactionType::where('merchant_id', $merchant->id)->get();
            foreach ($types as $type) {
                CommissionRule::firstOrCreate(
                    ['merchant_id' => $merchant->id, 'transaction_type_id' => $type->id],
                    [
                        'name' => "Default {$type->name} commission",
                        'rate_type' => 'percentage',
                        'rate_value' => 2.5,
                        'cap_amount' => null,
                        'min_amount' => null,
                        'max_amount' => null,
                        'priority' => 0,
                        'payer' => 'merchant',
                        'status' => true,
                    ]
                );
            }

            // Wallet for merchant
            $wallet = Wallet::firstOrCreate(
                ['merchant_id' => $merchant->id, 'user_identifier' => (string) $merchant->id, 'currency' => $mData['currency']],
                [
                    'type' => 'fiat',
                    'label' => 'Main Wallet',
                    'balance' => 0,
                    'ledger_balance' => 0,
                    'hold_balance' => 0,
                    'status' => true,
                ]
            );
        }

        // ── 3. Customers ──
        $customersData = [
            ['name' => 'Alice Johnson', 'email' => 'alice@example.com', 'phone' => '+2348111111111'],
            ['name' => 'Bob Smith', 'email' => 'bob@example.com', 'phone' => '+2348222222222'],
            ['name' => 'Charlie Brown', 'email' => 'charlie@example.com', 'phone' => '+2348333333333'],
            ['name' => 'Diana Prince', 'email' => 'diana@example.com', 'phone' => '+2348444444444'],
            ['name' => 'Eve Adams', 'email' => 'eve@example.com', 'phone' => '+2348555555555'],
            ['name' => 'Frank Castle', 'email' => 'frank@example.com', 'phone' => '+2348666666666'],
        ];

        foreach ($customersData as $cData) {
            Customer::firstOrCreate(
                ['email' => $cData['email']],
                $cData
            );
        }

        // ── 4. Transactions ──
        $merchants = Merchant::all();
        $escrowType = TransactionType::where('slug', 'escrow')->first();
        $directSaleType = TransactionType::where('slug', 'direct_sale')->first();

        $txnStatuses = ['Open', 'Pending', 'Released', 'Fulfilled', 'Flagged', 'PaymentPending'];
        $paymentGateways = ['paystack', 'stripe', 'flutterwave', 'seerbit'];
        $currencies = ['NGN', 'NGN', 'NGN', 'USD'];

        for ($i = 1; $i <= 50; $i++) {
            $merchant = $merchants->random();
            $customer = $customersData[array_rand($customersData)];
            $status = $txnStatuses[array_rand($txnStatuses)];
            $gateway = $paymentGateways[array_rand($paymentGateways)];
            $amount = rand(5000, 5000000);
            $currency = $currencies[array_rand($currencies)];
            $daysAgo = rand(1, 60);
            $type = rand(0, 1) ? $escrowType : $directSaleType;

            // Ensure transaction type belongs to this merchant
            $merchantType = TransactionType::where('merchant_id', $merchant->id)
                ->inRandomOrder()
                ->first();
            if (!$merchantType) continue;

            $feeRate = rand(1, 5) / 100;
            $fee = round($amount * $feeRate, 2);
            $net = round($amount - $fee, 2);

            $transcode = 'TXN-' . Carbon::now()->subDays($daysAgo)->format('Ymd') . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $txn = Transaction::firstOrCreate(
                ['transcode' => $transcode],
                [
                'posting_date' => Carbon::now()->subDays($daysAgo),
                'transcode' => $transcode,
                'customer_email' => $customer['email'],
                'merchant_email' => $merchant->email,
                'merchantid' => $merchant->id,
                'description' => 'Transaction ' . ($i + 1),
                'amount' => $amount,
                'country' => 'Nigeria',
                'currency' => $currency,
                'startdate' => Carbon::now()->subDays($daysAgo + 14)->format('Y-m-d'),
                'enddate' => Carbon::now()->addDays(14)->format('Y-m-d'),
                'fulfill_days' => '28 days',
                'payment_gateway' => $gateway,
                'payment_date' => in_array($status, ['Released', 'Fulfilled']) ? Carbon::now()->subDays($daysAgo) : null,
                'payment_status' => in_array($status, ['Released', 'Fulfilled']) ? 'Paid' : null,
                'trans_status' => $status,
                'releasedate' => $status === 'Released' ? Carbon::now()->subDays(rand(1, 10)) : null,
                'confirmed_by_merchant' => $status === 'Released' || $status === 'Fulfilled',
                'confirmed_date' => $status === 'Released' || $status === 'Fulfilled' ? Carbon::now()->subDays(rand(2, 12)) : null,
                'pepperest_fee' => $fee,
                'order_id' => $i,
                'appid' => (string) $merchant->id,
                'transaction_type_id' => $merchantType->id,
                'commission_amount' => $fee,
                'net_amount' => $net,
                'gateway_reference' => 'GWR-' . strtoupper(Str::random(16)),
                'metadata' => json_encode(['source' => 'demo_seeder', 'customer_name' => $customer['name']]),
            ]);

            // Transaction history
            TransactionHistory::create([
                'transcode' => $txn->transcode,
                'customer_email' => $txn->customer_email,
                'merchant_email' => $txn->merchant_email,
                'trans_status' => $txn->trans_status,
                'status_update_date' => Carbon::now()->subDays($daysAgo),
                'updatedby' => 'API ' . $merchant->id,
            ]);

            // App account
            AppAccount::create([
                'appid' => (string) $merchant->id,
                'referenceid' => $txn->transcode,
                'customer_account' => '0123456789',
                'customer_code' => '058',
                'merchant_account' => '9876543210',
                'merchant_code' => '058',
            ]);

            // Wallet credit for paid transactions
            if (in_array($status, ['Released', 'Fulfilled'])) {
                $wallet = Wallet::where('merchant_id', $merchant->id)
                    ->where('user_identifier', (string) $merchant->id)
                    ->first();
                if ($wallet) {
                    $wallet->increment('ledger_balance', $net);
                    $wallet->increment('balance', $net);
                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'credit',
                        'amount' => $net,
                        'balance_before' => $wallet->balance - $net,
                        'balance_after' => $wallet->balance,
                        'reference_type' => 'transaction',
                        'reference_id' => $txn->transcode,
                        'description' => "Escrow release: {$txn->transcode}",
                    ]);
                }
            }
        }

        // ── 5. Subscription Plans ──
        foreach ($merchants as $merchant) {
            $basicSlug = 'basic-monthly-' . $merchant->id;
            $proSlug = 'pro-yearly-' . $merchant->id;
            SubscriptionPlan::firstOrCreate(
                ['merchant_id' => $merchant->id, 'slug' => $basicSlug],
                [
                    'name' => 'Basic Monthly',
                    'description' => 'Entry-level subscription plan',
                    'amount' => 5000,
                    'currency' => 'NGN',
                    'billing_cycle' => 'monthly',
                    'cycle_interval' => 1,
                    'trial_days' => 7,
                    'is_active' => true,
                ]
            );
            SubscriptionPlan::firstOrCreate(
                ['merchant_id' => $merchant->id, 'slug' => $proSlug],
                [
                    'name' => 'Pro Yearly',
                    'description' => 'Professional plan billed annually',
                    'amount' => 50000,
                    'currency' => 'NGN',
                    'billing_cycle' => 'yearly',
                    'cycle_interval' => 1,
                    'trial_days' => 14,
                    'is_active' => true,
                ]
            );

            // Active subscriptions
            $plan = SubscriptionPlan::where('merchant_id', $merchant->id)->first();
            if ($plan) {
                $sub = Subscription::firstOrCreate(
                    ['merchant_id' => $merchant->id, 'customer_email' => 'alice@example.com', 'plan_id' => $plan->id],
                    [
                        'status' => 'active',
                        'starts_at' => Carbon::now()->subDays(20),
                        'ends_at' => Carbon::now()->addDays(10),
                        'trial_ends_at' => null,
                    ]
                );

                $invoiceNumber = 'INV-' . strtoupper(Str::random(12));
                SubscriptionInvoice::firstOrCreate(
                    ['invoice_number' => $invoiceNumber],
                    [
                    'merchant_id' => $merchant->id,
                    'subscription_id' => $sub->id,
                    'invoice_number' => $invoiceNumber,
                    'amount' => $plan->amount,
                    'currency' => $plan->currency,
                    'status' => 'paid',
                    'due_date' => Carbon::now()->subDays(20),
                    'paid_at' => Carbon::now()->subDays(20),
                    'billing_period' => 1,
                ]);
            }
        }

        // ── 6. Settlements ──
        foreach ($merchants as $merchant) {
            $settlableTxns = Transaction::where('appid', (string) $merchant->id)
                ->where('trans_status', 'Released')
                ->orWhere('trans_status', 'Fulfilled')
                ->whereNull('settled_at')
                ->get();

            if ($settlableTxns->count() >= 2) {
                $batchTxn = $settlableTxns->take(5);
                $totalAmount = $batchTxn->sum('amount');
                $totalCommission = $batchTxn->sum('commission_amount') ?: $batchTxn->sum('pepperest_fee');
                $netAmount = $totalAmount - $totalCommission;

                $batchNumber = 'STL-' . Carbon::now()->format('Ymd') . '-' . $merchant->id;
                $settlement = Settlement::firstOrCreate(
                    ['batch_number' => $batchNumber],
                    [
                    'merchant_id' => $merchant->id,
                    'batch_number' => $batchNumber,
                    'status' => rand(0, 2) === 0 ? 'completed' : 'pending',
                    'total_amount' => $totalAmount,
                    'total_commission' => $totalCommission,
                    'net_amount' => $netAmount,
                    'item_count' => $batchTxn->count(),
                    'currency' => 'NGN',
                    'payment_gateway' => 'paystack',
                    'processed_at' => rand(0, 1) ? Carbon::now()->subDays(rand(1, 5)) : null,
                ]);

                foreach ($batchTxn as $txnItem) {
                    SettlementItem::firstOrCreate(
                        ['settlement_id' => $settlement->id, 'transaction_id' => $txnItem->id],
                        [
                        'settlement_id' => $settlement->id,
                        'transaction_id' => $txnItem->id,
                        'transaction_amount' => $txnItem->amount,
                        'commission_amount' => $txnItem->commission_amount ?? $txnItem->pepperest_fee,
                        'net_amount' => ($txnItem->net_amount ?? $txnItem->amount),
                        'status' => $settlement->status === 'completed' ? 'paid' : 'included',
                    ]);
                }
            }
        }

        // ── 7. Reward Programs ──
        foreach ($merchants as $merchant) {
            RewardProgram::firstOrCreate(
                ['merchant_id' => $merchant->id, 'name' => 'Cashback 2%'],
                [
                    'description' => 'Earn 2% cashback on all escrow transactions',
                    'reward_type' => 'cashback',
                    'reward_value' => 2,
                    'min_transaction_amount' => 1000,
                    'is_active' => true,
                ]
            );

            RewardBalance::firstOrCreate(
                ['merchant_id' => $merchant->id, 'customer_email' => 'alice@example.com'],
                [
                    'reward_type' => 'cashback',
                    'balance' => 500,
                    'lifetime_earned' => 1500,
                    'lifetime_redeemed' => 1000,
                ]
            );

            RewardTransaction::create([
                'merchant_id' => $merchant->id,
                'customer_email' => 'alice@example.com',
                'reward_balance_id' => RewardBalance::where('merchant_id', $merchant->id)->first()?->id,
                'type' => 'earned',
                'amount' => 50,
                'description' => 'Cashback on transaction TXN-...',
                'reference_type' => 'transaction',
            ]);
        }

        // ── 8. Listing Fees ──
        foreach ($merchants as $merchant) {
            ListingFee::firstOrCreate(
                ['merchant_id' => $merchant->id, 'name' => 'Featured Listing'],
                [
                    'fee_type' => 'fixed',
                    'fee_value' => 2500,
                    'currency' => 'NGN',
                    'is_active' => true,
                ]
            );
            ListingFee::firstOrCreate(
                ['merchant_id' => $merchant->id, 'name' => 'Premium Promotion'],
                [
                    'fee_type' => 'percentage',
                    'fee_value' => 5,
                    'currency' => 'NGN',
                    'is_active' => true,
                ]
            );
        }

        // ── 9. Virtual Accounts ──
        foreach ($merchants as $merchant) {
            VirtualAccount::firstOrCreate(
                ['merchant_id' => $merchant->id, 'customer_email' => 'alice@example.com'],
                [
                    'customer_name' => 'Alice Johnson',
                    'account_number' => '812345' . str_pad((string) $merchant->id, 4, '0', STR_PAD_LEFT),
                    'account_name' => 'Alice Johnson',
                    'bank_name' => 'Providus Bank',
                    'gateway' => ApiToken::where('merchant_id', $merchant->id)->value('payment_gateway') ?? 'paystack',
                    'status' => 'active',
                ]
            );
        }

        // ── 10. Notification Logs ──
        $events = [
            'payment.received',
            'transaction.released',
            'dispute.filed',
            'settlement.completed',
            'subscription.billed',
            'reward.earned',
        ];
        $channels = ['webhook', 'email'];
        $statuses = ['sent', 'sent', 'failed', 'pending'];

        foreach ($merchants as $merchant) {
            for ($j = 0; $j < 8; $j++) {
                NotificationLog::create([
                    'merchant_id' => $merchant->id,
                    'channel' => $channels[array_rand($channels)],
                    'event' => $events[array_rand($events)],
                    'recipient' => $merchant->webhook_url ?? $merchant->email,
                    'subject' => 'Notification ' . ($j + 1),
                    'body' => 'This is a sample notification body for notification ' . ($j + 1) . '.',
                    'status' => $statuses[array_rand($statuses)],
                    'attempts' => rand(1, 3),
                    'response_code' => rand(0, 1) ? 200 : 500,
                    'sent_at' => rand(0, 1) ? Carbon::now()->subHours(rand(1, 48)) : null,
                ]);
            }
        }
    }
}
