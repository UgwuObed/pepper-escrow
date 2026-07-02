@extends('admin.layout')
@section('title', 'Revenue')
@section('content')
<div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Platform Revenue</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Volume</div>
            <div class="text-2xl font-bold">{{ number_format($totalVolume, 2) }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Commission</div>
            <div class="text-2xl font-bold text-green-600">{{ number_format($totalCommission, 2) }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Transactions</div>
            <div class="text-2xl font-bold">{{ number_format($totalTransactions) }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Active Merchants</div>
            <div class="text-2xl font-bold">{{ number_format($totalMerchants) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">This Month Volume</div>
            <div class="text-2xl font-bold">{{ number_format($monthVolume, 2) }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">This Month Commission</div>
            <div class="text-2xl font-bold text-green-600">{{ number_format($monthCommission, 2) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-bold mb-4">Volume by Gateway</h2>
            <div class="space-y-3">
@forelse ($byGateway as $gw)
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium capitalize">{{ $gw->payment_gateway }}</span>
                        <span>{{ number_format($gw->volume, 2) }}</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded h-2">
                        @php $pct = $totalVolume > 0 ? ($gw->volume / $totalVolume) * 100 : 0; @endphp
                        <div class="bg-blue-600 h-2 rounded" style="width: {{ $pct }}%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500 mt-1">
                        <span>{{ $gw->count }} txns</span>
                        <span>Commission: {{ number_format($gw->commission, 2) }}</span>
                    </div>
                </div>
@empty
                <p class="text-gray-500 text-sm">No gateway data.</p>
@endforelse
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-bold mb-4">Monthly Trend (Last 12)</h2>
            <canvas id="trendChart" height="200"></canvas>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="text-lg font-bold">Merchant Breakdown</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">Merchant</th>
                        <th class="px-6 py-3">Transactions</th>
                        <th class="px-6 py-3">Volume</th>
                        <th class="px-6 py-3">Commission</th>
                        <th class="px-6 py-3">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
@forelse ($merchantBreakdown as $mb)
                    <tr class="hover:bg-gray-50 text-sm">
                        <td class="px-6 py-3"><a href="{{ route('admin.merchant.detail', $mb->id) }}" class="text-blue-600 hover:underline">{{ $mb->business_name }}</a></td>
                        <td class="px-6 py-3">{{ number_format($mb->transaction_count ?? 0) }}</td>
                        <td class="px-6 py-3">{{ number_format($mb->volume ?? 0, 2) }}</td>
                        <td class="px-6 py-3">{{ number_format($mb->commission ?? 0, 2) }}</td>
                        <td class="px-6 py-3">
                            @php $sharePct = $totalCommission > 0 ? (($mb->commission ?? 0) / $totalCommission) * 100 : 0; @endphp
                            <div class="flex items-center gap-2">
                                <div class="w-24 bg-gray-200 rounded h-2">
                                    <div class="bg-green-500 h-2 rounded" style="width: {{ $sharePct }}%"></div>
                                </div>
                                <span class="text-xs">{{ number_format($sharePct, 1) }}%</span>
                            </div>
                        </td>
                    </tr>
@empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No merchant data.</td></tr>
@endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: {!! json_encode($monthlyTrend->reverse()->map(fn($t) => date('M Y', mktime(0,0,0,$t->month,1,$t->year)))->values()) !!},
        datasets: [
            {
                label: 'Volume',
                data: {!! json_encode($monthlyTrend->reverse()->pluck('volume')->values()) !!},
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1
            },
            {
                label: 'Commission',
                data: {!! json_encode($monthlyTrend->reverse()->pluck('commission')->values()) !!},
                backgroundColor: 'rgba(16, 185, 129, 0.5)',
                borderColor: 'rgb(16, 185, 129)',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>
@endsection
