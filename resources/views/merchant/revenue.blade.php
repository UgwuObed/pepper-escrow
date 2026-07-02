@extends('merchant.layout')
@section('title', 'Revenue & Commission')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Revenue & Commission</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.settlements') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settlements</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    @php
        $totals = $monthlyData->reduce(fn($c, $m) => [
            'volume' => $c['volume'] + (float) ($m->volume ?? 0),
            'commission' => $c['commission'] + (float) ($m->commission ?? 0),
            'count' => $c['count'] + (int) ($m->count ?? 0),
        ], ['volume' => 0, 'commission' => 0, 'count' => 0]);
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Volume</h2>
            <p class="text-3xl font-bold">{{ number_format($totals['volume'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Commission Earned</h2>
            <p class="text-3xl font-bold text-green-600">{{ number_format($totals['commission'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Transactions</h2>
            <p class="text-3xl font-bold">{{ $totals['count'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Settlements Paid</h2>
            <p class="text-3xl font-bold text-blue-600">{{ number_format((float) ($settlementStats->total_paid ?? 0), 2) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        {{-- Monthly Trend Chart --}}
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">Monthly Trend (Last 12)</h2>
            <canvas id="trendChart" height="250"></canvas>
        </div>

        {{-- By Transaction Type --}}
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">Commission by Type</h2>
            @if ($typeBreakdown->isNotEmpty())
            <div class="space-y-3">
                @foreach ($typeBreakdown as $item)
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium">{{ $item['name'] }}</span>
                        <span>{{ number_format($item['commission'], 2) }} ({{ $item['count'] }} txns)</span>
                    </div>
                    @php $pct = $totals['commission'] > 0 ? ($item['commission'] / $totals['commission'] * 100) : 0; @endphp
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-gray-500 text-center py-6">No transaction type data yet.</p>
            @endif
        </div>
    </div>

    {{-- Monthly Data Table --}}
    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Monthly Breakdown</h2>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Month</th>
                        <th class="pb-2">Transactions</th>
                        <th class="pb-2">Volume</th>
                        <th class="pb-2">Commission</th>
                        <th class="pb-2">Effective Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($monthlyData as $row)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-medium">{{ $row->month }}</td>
                        <td class="py-3">{{ $row->count }}</td>
                        <td class="py-3">{{ number_format($row->volume, 2) }}</td>
                        <td class="py-3 text-green-600 font-semibold">{{ number_format($row->commission, 2) }}</td>
                        <td class="py-3">{{ $row->volume > 0 ? number_format($row->commission / $row->volume * 100, 2) : 0 }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-500">No transaction data yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: {!! json_encode($monthlyData->pluck('month')) !!},
        datasets: [
            {
                label: 'Volume',
                data: {!! json_encode($monthlyData->pluck('volume')->map(fn($v) => (float) $v)) !!},
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1,
                yAxisID: 'y',
            },
            {
                label: 'Commission',
                data: {!! json_encode($monthlyData->pluck('commission')->map(fn($c) => (float) $c)) !!},
                backgroundColor: 'rgba(16, 185, 129, 0.5)',
                borderColor: 'rgb(16, 185, 129)',
                borderWidth: 1,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Volume' } },
            y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Commission' }, grid: { drawOnChartArea: false } },
        }
    }
});
</script>
@endsection
