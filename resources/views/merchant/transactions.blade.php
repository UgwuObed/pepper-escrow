@extends('merchant.layout')
@section('title', 'Transactions')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Transactions</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.api-keys') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">API Keys</a>
            <a href="{{ route('merchant.settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settings</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-2">Code</th>
                    <th class="pb-2">Amount</th>
                    <th class="pb-2">Status</th>
                    <th class="pb-2">Customer</th>
                    <th class="pb-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $txn)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2 font-mono text-sm">{{ $txn->transcode }}</td>
                    <td class="py-2">{{ number_format($txn->amount, 2) }}</td>
                    <td class="py-2"><span class="px-2 py-1 rounded text-sm {{ $txn->status === 'released' ? 'bg-green-100 text-green-700' : ($txn->status === 'Disputed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $txn->status ?? $txn->trans_status }}</span></td>
                    <td class="py-2">{{ $txn->customer_email ?? '-' }}</td>
                    <td class="py-2">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-4 text-center text-gray-500">No transactions found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">
            {{ $transactions->links() }}
        </div>
    </div>
</div>
@endsection
