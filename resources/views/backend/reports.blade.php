@extends('backend.layout')
@section('title', 'Reports')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Reports</h1>
        <div>
            <a href="{{ route('escrow.dashboard') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 mr-2">Back</a>
            <a href="{{ route('escrow.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-xl font-bold mb-4">Transaction Report</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="p-4 bg-blue-50 rounded">
                    <h3 class="text-sm font-semibold text-gray-600">Total Amount</h3>
                <p class="text-2xl font-bold">{{ number_format($tranx_sum ?? 0, 2) }}</p>
            </div>
            <div class="p-4 bg-green-50 rounded">
                <h3 class="text-sm font-semibold text-gray-600">Monthly Volume</h3>
                <p class="text-2xl font-bold">{{ number_format($tranx_month_sum ?? 0, 2) }}</p>
            </div>
            <div class="p-4 bg-yellow-50 rounded">
                <h3 class="text-sm font-semibold text-gray-600">Total Transactions</h3>
                <p class="text-2xl font-bold">{{ $total_tranx ?? 0 }}</p>
            </div>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">All Transactions</h2>
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-2">Code</th>
                    <th class="pb-2">Customer</th>
                    <th class="pb-2">Merchant</th>
                    <th class="pb-2">Amount</th>
                    <th class="pb-2">Fee</th>
                    <th class="pb-2">Status</th>
                    <th class="pb-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions ?? [] as $txn)
                <tr class="border-b">
                    <td class="py-2">{{ $txn->transcode }}</td>
                    <td class="py-2">{{ $txn->customer_email }}</td>
                    <td class="py-2">{{ $txn->merchant_email }}</td>
                    <td class="py-2">{{ number_format($txn->amount, 2) }}</td>
                    <td class="py-2">{{ number_format($txn->pepperest_fee ?? 0, 2) }}</td>
                    <td class="py-2"><span class="px-2 py-1 rounded text-sm {{ $txn->trans_status === 'Released' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $txn->trans_status }}</span></td>
                    <td class="py-2">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-4 text-center text-gray-500">No transactions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
