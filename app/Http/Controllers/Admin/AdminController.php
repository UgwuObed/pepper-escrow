<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Settlement;
use App\Models\NotificationLog;
use App\Models\Wallet;
use App\Models\ClientConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalMerchants = Merchant::count();
        $activeMerchants = Merchant::where('status', 1)->count();
        $totalTransactions = Transaction::count();
        $totalVolume = Transaction::sum('amount');
        $totalCommission = Transaction::sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)'));
        $pendingSettlements = Settlement::where('status', 'pending')->count();
        $completedSettlements = Settlement::where('status', 'completed')->count();
        $recentTransactions = Transaction::orderBy('id', 'desc')->take(10)->get();

        $monthStart = Carbon::now()->startOfMonth();
        $monthVolume = Transaction::where('posting_date', '>=', $monthStart)->sum('amount');
        $monthCommission = Transaction::where('posting_date', '>=', $monthStart)
            ->sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)'));

        return view('admin.dashboard', compact(
            'totalMerchants', 'activeMerchants', 'totalTransactions', 'totalVolume',
            'totalCommission', 'pendingSettlements', 'completedSettlements',
            'recentTransactions', 'monthVolume', 'monthCommission'
        ));
    }

    public function merchants(Request $request)
    {
        $query = Merchant::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        $merchants = $query->withCount(['apiToken as transaction_count' => function ($q) {
            $q->select(DB::raw('COALESCE((SELECT COUNT(*) FROM transactions WHERE appid = api_tokens.app_id), 0)'));
        }])->orderBy('id', 'desc')->paginate(20)->withQueryString();

        return view('admin.merchants.index', compact('merchants'));
    }

    public function merchantDetail($id)
    {
        $merchant = Merchant::findOrFail($id);
        $config = ClientConfig::where('merchant_id', $id)->first();
        $wallets = Wallet::byMerchant($id)->active()->get();
        $transactions = Transaction::where('appid', $id)->orderBy('id', 'desc')->take(20)->get();
        $volume = Transaction::where('appid', $id)->sum('amount');
        $transactionCount = Transaction::where('appid', $id)->count();

        return view('admin.merchants.show', compact(
            'merchant', 'config', 'wallets', 'transactions', 'volume', 'transactionCount'
        ));
    }

    public function merchantToggleStatus($id)
    {
        $merchant = Merchant::findOrFail($id);
        $merchant->update(['status' => !$merchant->status]);

        return redirect()->back()->with('message',
            "Merchant " . ($merchant->status ? 'activated' : 'suspended') . " successfully."
        );
    }

    public function transactions(Request $request)
    {
        $query = Transaction::query();

        if ($merchantId = $request->get('merchant_id')) {
            $query->where('appid', $merchantId);
        }

        if ($status = $request->get('status')) {
            $query->where('trans_status', $status);
        }

        if ($gateway = $request->get('gateway')) {
            $query->where('payment_gateway', $gateway);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('posting_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->where('posting_date', '<=', $dateTo . ' 23:59:59');
        }

        $transactions = $query->orderBy('id', 'desc')->paginate(30)->withQueryString();
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        $summary = (object) [
            'total' => $query->count(),
            'volume' => $query->sum('amount'),
            'commission' => $query->sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)')),
        ];

        return view('admin.transactions.index', compact('transactions', 'merchants', 'summary'));
    }

    public function transactionDetail($id)
    {
        $transaction = Transaction::findOrFail($id);
        $merchant = Merchant::find($transaction->appid);

        return view('admin.transactions.show', compact('transaction', 'merchant'));
    }

    public function settlements(Request $request)
    {
        $query = Settlement::query();

        if ($merchantId = $request->get('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $settlements = $query->with('merchant')->orderBy('id', 'desc')->paginate(20)->withQueryString();
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        return view('admin.settlements.index', compact('settlements', 'merchants'));
    }

    public function settlementDetail($id)
    {
        $settlement = Settlement::with(['merchant', 'items.transaction'])->findOrFail($id);

        return view('admin.settlements.show', compact('settlement'));
    }

    public function notificationLogs(Request $request)
    {
        $query = NotificationLog::query();

        if ($merchantId = $request->get('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($event = $request->get('event')) {
            $query->where('event', $event);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $logs = $query->with('merchant')->orderBy('id', 'desc')->paginate(30)->withQueryString();
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);
        $events = NotificationLog::select('event')->distinct()->pluck('event');

        return view('admin.notifications.index', compact('logs', 'merchants', 'events'));
    }

    public function revenue()
    {
        $totalVolume = Transaction::sum('amount');
        $totalCommission = Transaction::sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)'));
        $totalTransactions = Transaction::count();
        $totalMerchants = Merchant::count();

        $monthStart = Carbon::now()->startOfMonth();
        $monthVolume = Transaction::where('posting_date', '>=', $monthStart)->sum('amount');
        $monthCommission = Transaction::where('posting_date', '>=', $monthStart)
            ->sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)'));

        $byGateway = Transaction::select('payment_gateway',
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(amount) as volume'),
            DB::raw('SUM(COALESCE(commission_amount, pepperest_fee, 0)) as commission')
        )->whereNotNull('payment_gateway')
            ->groupBy('payment_gateway')
            ->get();

        $monthlyTrend = Transaction::select(
            DB::raw('YEAR(posting_date) as year'),
            DB::raw('MONTH(posting_date) as month'),
            DB::raw('SUM(amount) as volume'),
            DB::raw('SUM(COALESCE(commission_amount, pepperest_fee, 0)) as commission'),
            DB::raw('COUNT(*) as count')
        )->whereNotNull('posting_date')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->take(12)
            ->get();

        $merchantBreakdown = Merchant::select('id', 'business_name')
            ->withCount(['apiToken as transaction_count' => function ($q) {
                $q->select(DB::raw('COALESCE((SELECT COUNT(*) FROM transactions WHERE appid = api_tokens.app_id), 0)'));
            }])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($merchant) {
                $merchant->volume = Transaction::where('appid', $merchant->id)->sum('amount');
                $merchant->commission = Transaction::where('appid', $merchant->id)
                    ->sum(DB::raw('COALESCE(commission_amount, pepperest_fee, 0)'));
                return $merchant;
            });

        return view('admin.revenue', compact(
            'totalVolume', 'totalCommission', 'totalTransactions', 'totalMerchants',
            'monthVolume', 'monthCommission', 'byGateway', 'monthlyTrend', 'merchantBreakdown'
        ));
    }
}
