<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\TransactionType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $period = $request->input('period', 'all');
        $merchantId = $request->input('merchant_id');
        $dateFrom = $this->parsePeriod($period);

        $query = Transaction::query()
            ->where('payment_status', 'Paid')
            ->where('trans_status', 'Closed');

        if ($merchantId) {
            $query->where('appid', $merchantId);
        }

        if ($dateFrom) {
            $query->where('payment_date', '>=', $dateFrom);
        }

        $totalRevenue = (float) $query->sum('amount') ?? 0;
        $totalCommission = (float) $query->sum('commission_amount') ?? 0;
        $totalNet = (float) $query->sum('net_amount') ?? 0;
        $transactionCount = $query->count();

        $settledCommission = (float) Settlement::query()
            ->when($merchantId, fn($q) => $q->where('merchant_id', $merchantId))
            ->where('status', 'completed')
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
            ->sum('total_commission') ?? 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $period,
                'total_transaction_volume' => $totalRevenue,
                'total_commission_earned' => $totalCommission,
                'total_net_to_merchants' => $totalNet,
                'total_settled_commission' => $settledCommission,
                'pending_commission' => max(0, $totalCommission - $settledCommission),
                'transaction_count' => $transactionCount,
            ],
        ]);
    }

    public function byTransactionType(Request $request): JsonResponse
    {
        $period = $request->input('period', 'all');
        $merchantId = $request->input('merchant_id');
        $dateFrom = $this->parsePeriod($period);

        $query = Transaction::query()
            ->select(
                'transaction_type_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('SUM(commission_amount) as total_commission'),
                DB::raw('SUM(net_amount) as total_net'),
            )
            ->where('payment_status', 'Paid')
            ->whereNotNull('transaction_type_id')
            ->groupBy('transaction_type_id');

        if ($merchantId) {
            $query->where('appid', $merchantId);
        }

        if ($dateFrom) {
            $query->where('payment_date', '>=', $dateFrom);
        }

        $results = $query->get()->map(function ($row) {
            $type = TransactionType::find($row->transaction_type_id);
            return [
                'transaction_type_id' => $row->transaction_type_id,
                'type_name' => $type?->name ?? 'Unknown',
                'type_slug' => $type?->slug ?? 'unknown',
                'count' => (int) $row->count,
                'total_volume' => (float) ($row->total_volume ?? 0),
                'total_commission' => (float) ($row->total_commission ?? 0),
                'total_net' => (float) ($row->total_net ?? 0),
            ];
        })->sortByDesc('total_commission')->values();

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    public function trend(Request $request): JsonResponse
    {
        $granularity = $request->input('granularity', 'monthly');
        $months = (int) $request->input('months', 12);
        $merchantId = $request->input('merchant_id');

        $dateFrom = now()->subMonths($months)->startOfMonth();

        $dateFormat = match ($granularity) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m',
        };

        $groupExpr = DB::raw("DATE_FORMAT(payment_date, '{$dateFormat}') as period");

        $query = Transaction::query()
            ->select(
                $groupExpr,
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as volume'),
                DB::raw('SUM(commission_amount) as commission'),
                DB::raw('SUM(net_amount) as net'),
            )
            ->where('payment_status', 'Paid')
            ->where('payment_date', '>=', $dateFrom)
            ->groupBy('period')
            ->orderBy('period');

        if ($merchantId) {
            $query->where('appid', $merchantId);
        }

        $results = $query->get()->map(function ($row) {
            return [
                'period' => $row->period,
                'count' => (int) $row->count,
                'volume' => (float) ($row->volume ?? 0),
                'commission' => (float) ($row->commission ?? 0),
                'net' => (float) ($row->net ?? 0),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    public function mrr(Request $request): JsonResponse
    {
        $merchantId = $request->input('merchant_id');

        $query = Subscription::query()->where('status', 'active');

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        $activeSubscriptions = $query->with('plan')->get();

        $mrr = 0;
        $arr = 0;
        $byCycle = ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0];

        foreach ($activeSubscriptions as $sub) {
            $plan = $sub->plan;
            if (!$plan) continue;

            $monthlyValue = match ($plan->billing_cycle) {
                'daily' => (float) $plan->amount * 30 / max($plan->cycle_interval, 1),
                'weekly' => (float) $plan->amount * 4 / max($plan->cycle_interval, 1),
                'monthly' => (float) $plan->amount / max($plan->cycle_interval, 1),
                'yearly' => (float) $plan->amount / 12 / max($plan->cycle_interval, 1),
                default => (float) $plan->amount,
            };

            $mrr += $monthlyValue;
            $arr += $monthlyValue * 12;
            $byCycle[$plan->billing_cycle] += $monthlyValue;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'mrr' => round($mrr, 2),
                'arr' => round($arr, 2),
                'active_subscriptions' => $activeSubscriptions->count(),
                'breakdown_by_cycle' => [
                    'daily' => round($byCycle['daily'], 2),
                    'weekly' => round($byCycle['weekly'], 2),
                    'monthly' => round($byCycle['monthly'], 2),
                    'yearly' => round($byCycle['yearly'], 2),
                ],
            ],
        ]);
    }

    public function merchantBreakdown(Request $request): JsonResponse
    {
        $period = $request->input('period', 'all');
        $dateFrom = $this->parsePeriod($period);

        $query = Transaction::query()
            ->select(
                'appid',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as volume'),
                DB::raw('SUM(commission_amount) as commission'),
                DB::raw('SUM(net_amount) as net'),
            )
            ->where('payment_status', 'Paid')
            ->whereNotNull('appid')
            ->groupBy('appid');

        if ($dateFrom) {
            $query->where('payment_date', '>=', $dateFrom);
        }

        $results = $query->get()->map(function ($row) {
            $merchant = \App\Models\Merchant::find($row->appid);
            return [
                'merchant_id' => $row->appid,
                'business_name' => $merchant?->business_name ?? "Merchant #{$row->appid}",
                'count' => (int) $row->count,
                'volume' => (float) ($row->volume ?? 0),
                'commission' => (float) ($row->commission ?? 0),
                'net' => (float) ($row->net ?? 0),
            ];
        })->sortByDesc('commission')->values();

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    protected function parsePeriod(?string $period): ?Carbon
    {
        return match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            'mtd' => now()->startOfMonth(),
            'ytd' => now()->startOfYear(),
            default => null,
        };
    }
}
