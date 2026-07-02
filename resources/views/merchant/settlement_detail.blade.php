@extends('merchant.layout')
@section('title', 'Settlement Detail')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Settlement {{ $settlement->batch_number }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.settlements') }}" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Back to Settlements</a>
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Amount</h2>
            <p class="text-3xl font-bold">{{ number_format($settlement->total_amount, 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Commission</h2>
            <p class="text-3xl font-bold text-red-600">{{ number_format($settlement->total_commission, 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Net Payout</h2>
            <p class="text-3xl font-bold text-green-600">{{ number_format($settlement->net_amount, 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Status</h2>
            <p class="text-3xl font-bold
                {{ $settlement->status === 'completed' ? 'text-green-600' : '' }}
                {{ $settlement->status === 'pending' ? 'text-yellow-600' : '' }}
                {{ $settlement->status === 'failed' ? 'text-red-600' : '' }}
            ">{{ ucfirst(str_replace('_', ' ', $settlement->status)) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="text-sm font-semibold text-gray-600">Gateway</h3>
            <p class="font-medium capitalize">{{ $settlement->payment_gateway ?? '—' }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="text-sm font-semibold text-gray-600">Gateway Ref</h3>
            <p class="font-mono text-sm">{{ $settlement->gateway_transfer_ref ?? '—' }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="text-sm font-semibold text-gray-600">Processed At</h3>
            <p class="font-medium">{{ $settlement->processed_at?->format('Y-m-d H:i') ?? '—' }}</p>
        </div>
    </div>

    @if ($settlement->notes)
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded mb-8">
        <strong>Notes:</strong> {{ $settlement->notes }}
    </div>
    @endif

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Transactions in this Batch</h2>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Transaction</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Amount</th>
                        <th class="pb-2">Commission</th>
                        <th class="pb-2">Net</th>
                        <th class="pb-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settlement->items as $item)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-mono text-sm">{{ $item->transaction->transcode ?? 'N/A' }}</td>
                        <td class="py-3">{{ $item->transaction->customer_email ?? '—' }}</td>
                        <td class="py-3">{{ number_format($item->transaction_amount, 2) }}</td>
                        <td class="py-3 text-red-600">{{ number_format($item->commission_amount, 2) }}</td>
                        <td class="py-3 font-semibold">{{ number_format($item->net_amount, 2) }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $item->status === 'paid' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $item->status === 'included' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $item->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $item->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                            ">{{ ucfirst($item->status) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-500">No items in this batch.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
