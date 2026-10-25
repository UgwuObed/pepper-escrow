<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Settlement;
use App\Models\SettlementHold;
use App\Services\SettlementReconciliationService;
use App\Services\SettlementRulesEngine;
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


    /**
     * Held funds: what is waiting, what is due, and what is stuck.
     */
    public function holds(Request $request)
    {
        $query = SettlementHold::query();

        if ($merchantId = $request->get('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // "Needs attention" is the view an operator actually opens: holds
        // that are disputed, past the Stripe limit, or close enough to it
        // that waiting is no longer safe.
        if ($request->boolean('attention')) {
            $query->where(function ($q) {
                $q->where('status', SettlementHold::STATUS_DISPUTED)
                    ->orWhere(function ($q2) {
                        $q2->whereIn('status', [SettlementHold::STATUS_HELD, SettlementHold::STATUS_PART_RELEASED])
                            ->where('hold_expires_at', '<=', now()->addDays(7));
                    });
            });
        }

        $holds = $query->with(['connectedAccount', 'rule'])
            ->orderByRaw('hold_expires_at is null, hold_expires_at asc')
            ->paginate(25)
            ->withQueryString();

        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        $summary = [
            'held' => SettlementHold::releasable()->sum(DB::raw('amount - amount_released - amount_refunded')),
            'disputed' => SettlementHold::where('status', SettlementHold::STATUS_DISPUTED)->count(),
            'expiring' => SettlementHold::releasable()
                ->whereNotNull('hold_expires_at')
                ->where('hold_expires_at', '<=', now()->addDays(7))->count(),
            'overdue' => SettlementHold::releasable()
                ->whereNotNull('hold_expires_at')
                ->where('hold_expires_at', '<', now())->count(),
        ];

        return view('admin.holds.index', compact('holds', 'merchants', 'summary'));
    }

    public function holdDetail($id)
    {
        $hold = SettlementHold::with(['connectedAccount', 'rule', 'events'])->findOrFail($id);

        return view('admin.holds.show', compact('hold'));
    }

    /**
     * Compare our record of what was released against Stripe's record of
     * what was paid out.
     */
    public function reconciliation(Request $request, SettlementReconciliationService $reconciler)
    {
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);
        $merchantId = $request->get('merchant_id');
        $report = null;
        $error = null;

        if ($merchantId) {
            try {
                $report = $reconciler->reconcileMerchant((int) $merchantId);
                $report['summary'] = $reconciler->summarise($report);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('admin.holds.reconciliation', compact('merchants', 'merchantId', 'report', 'error'));
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
