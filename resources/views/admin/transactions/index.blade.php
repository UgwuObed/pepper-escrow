@extends('admin.layout')
@section('title', 'Transactions')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Transactions</h1>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">Total</div>
            <div class="text-xl font-bold">{{ number_format($summary->total) }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">Volume</div>
            <div class="text-xl font-bold">{{ number_format($summary->volume, 2) }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">Commission</div>
            <div class="text-xl font-bold">{{ number_format($summary->commission, 2) }}</div>
        </div>
    </div>

    <form method="GET" class="bg-white p-4 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Merchant</label>
                <select name="merchant_id" class="w-full px-3 py-2 border rounded text-sm">
                    <option value="">All</option>
                    @foreach ($merchants as $m)
                    <option value="{{ $m->id }}" {{ request('merchant_id') == $m->id ? 'selected' : '' }}>{{ $m->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border rounded text-sm">
                    <option value="">All</option>
                    <option value="Pending" {{ request('status') === 'Pending' ? 'selected' : '' }}>Pending</option>
                    <option value="Fulfilled" {{ request('status') === 'Fulfilled' ? 'selected' : '' }}>Fulfilled</option>
                    <option value="Released" {{ request('status') === 'Released' ? 'selected' : '' }}>Released</option>
                    <option value="Cancelled" {{ request('status') === 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Gateway</label>
                <select name="gateway" class="w-full px-3 py-2 border rounded text-sm">
                    <option value="">All</option>
                    <option value="paystack" {{ request('gateway') === 'paystack' ? 'selected' : '' }}>Paystack</option>
                    <option value="stripe" {{ request('gateway') === 'stripe' ? 'selected' : '' }}>Stripe</option>
                    <option value="flutterwave" {{ request('gateway') === 'flutterwave' ? 'selected' : '' }}>Flutterwave</option>
                    <option value="seerbit" {{ request('gateway') === 'seerbit' ? 'selected' : '' }}>SeerBit</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Date From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full px-3 py-2 border rounded text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Date To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full px-3 py-2 border rounded text-sm">
            </div>
        </div>
        <div class="mt-3 flex gap-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
            <a href="{{ route('admin.transactions') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
        </div>
    </form>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">ID</th>
                        <th class="px-6 py-3">Code</th>
                        <th class="px-6 py-3">Merchant</th>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Amount</th>
                        <th class="px-6 py-3">Commission</th>
                        <th class="px-6 py-3">Net</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Gateway</th>
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($transactions as $txn)
                    <tr class="hover:bg-gray-50 text-sm">
                        <td class="px-6 py-3">{{ $txn->id }}</td>
                        <td class="px-6 py-3 font-mono">{{ $txn->transcode }}</td>
                        <td class="px-6 py-3">{{ $txn->merchant_email ?? $txn->appid }}</td>
                        <td class="px-6 py-3">{{ $txn->customer_email }}</td>
                        <td class="px-6 py-3">{{ number_format($txn->amount, 2) }}</td>
                        <td class="px-6 py-3">{{ number_format($txn->commission_amount ?? $txn->pepperest_fee ?? 0, 2) }}</td>
                        <td class="px-6 py-3">{{ number_format($txn->net_amount ?? $txn->amount, 2) }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 text-xs rounded {{ $txn->trans_status === 'Released' ? 'bg-green-100 text-green-700' : ($txn->trans_status === 'Fulfilled' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $txn->trans_status }}</span>
                        </td>
                        <td class="px-6 py-3">{{ $txn->payment_gateway ?? '-' }}</td>
                        <td class="px-6 py-3 text-gray-500">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                        <td class="px-6 py-3"><a href="{{ route('admin.transaction.detail', $txn->id) }}" class="text-blue-600 hover:underline">View</a></td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="px-6 py-8 text-center text-gray-500">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">
        {{ $transactions->links() }}
    </div>
</div>
@endsection
