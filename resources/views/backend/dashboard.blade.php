@extends('backend.layout')
@section('title', 'Dashboard')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Dashboard</h1>
        <a href="{{ route('escrow.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Transactions</h2>
            <p class="text-3xl font-bold">{{ $total_tranx ?? 0 }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Fulfilled</h2>
            <p class="text-3xl font-bold text-green-600">{{ $total_fulfilled_tranx ?? 0 }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Monthly Volume</h2>
            <p class="text-3xl font-bold text-blue-600">{{ number_format($tranx_month_sum ?? 0, 2) }}</p>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Recent Transactions</h2>
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-2">Code</th>
                    <th class="pb-2">Customer</th>
                    <th class="pb-2">Amount</th>
                    <th class="pb-2">Status</th>
                    <th class="pb-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions ?? [] as $txn)
                <tr class="border-b">
                    <td class="py-2">{{ $txn->transcode }}</td>
                    <td class="py-2">{{ $txn->customer_email }}</td>
                    <td class="py-2">{{ number_format($txn->amount, 2) }}</td>
                    <td class="py-2"><span class="px-2 py-1 rounded text-sm {{ $txn->trans_status === 'Released' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $txn->trans_status }}</span></td>
                    <td class="py-2">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-4 text-center text-gray-500">No transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
