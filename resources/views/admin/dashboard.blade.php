@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Platform Overview</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Merchants</div>
            <div class="text-2xl font-bold">{{ $totalMerchants }}</div>
            <div class="text-xs text-gray-400">{{ $activeMerchants }} active</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Transactions</div>
            <div class="text-2xl font-bold">{{ number_format($totalTransactions) }}</div>
            <div class="text-xs text-gray-400">All time</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Volume</div>
            <div class="text-2xl font-bold">{{ number_format($totalVolume, 2) }}</div>
            <div class="text-xs text-gray-400">This month: {{ number_format($monthVolume, 2) }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Total Commission</div>
            <div class="text-2xl font-bold">{{ number_format($totalCommission, 2) }}</div>
            <div class="text-xs text-gray-400">This month: {{ number_format($monthCommission, 2) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Pending Settlements</div>
            <div class="text-2xl font-bold text-yellow-600">{{ $pendingSettlements }}</div>
        </div>
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="text-sm text-gray-500 mb-1">Completed Settlements</div>
            <div class="text-2xl font-bold text-green-600">{{ $completedSettlements }}</div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="text-lg font-bold">Recent Transactions</h2>
            <a href="{{ route('admin.transactions') }}" class="text-sm text-blue-600 hover:underline">View all</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">Code</th>
                        <th class="px-6 py-3">Merchant</th>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Amount</th>
                        <th class="px-6 py-3">Commission</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($recentTransactions as $txn)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-mono">{{ $txn->transcode }}</td>
                        <td class="px-6 py-3 text-sm">{{ $txn->merchant_email ?? 'N/A' }}</td>
                        <td class="px-6 py-3 text-sm">{{ $txn->customer_email }}</td>
                        <td class="px-6 py-3 text-sm">{{ number_format($txn->amount, 2) }}</td>
                        <td class="px-6 py-3 text-sm">{{ number_format($txn->commission_amount ?? $txn->pepperest_fee ?? 0, 2) }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 text-xs rounded {{ $txn->trans_status === 'Released' ? 'bg-green-100 text-green-700' : ($txn->trans_status === 'Fulfilled' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $txn->trans_status }}</span>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                        <td class="px-6 py-3"><a href="{{ route('admin.transaction.detail', $txn->id) }}" class="text-blue-600 hover:underline text-sm">View</a></td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
