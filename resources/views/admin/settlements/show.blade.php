@extends('admin.layout')
@section('title', 'Settlement #' . $settlement->id)
@section('content')
<div class="p-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('admin.settlements') }}" class="text-blue-600 hover:underline">&larr; Back</a>
        <h1 class="text-2xl font-bold">Settlement {{ $settlement->batch_number }}</h1>
        <span class="px-2 py-1 text-xs rounded {{ $settlement->status === 'completed' ? 'bg-green-100 text-green-700' : ($settlement->status === 'failed' ? 'bg-red-100 text-red-700' : ($settlement->status === 'processing' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700')) }}">{{ ucfirst($settlement->status) }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Batch Details</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Merchant</dt><dd>{{ $settlement->merchant->business_name ?? 'N/A' }}</dd></div>
                    <div><dt class="text-gray-500">Total Amount</dt><dd class="font-bold">{{ number_format($settlement->total_amount, 2) }}</dd></div>
                    <div><dt class="text-gray-500">Total Commission</dt><dd>{{ number_format($settlement->total_commission, 2) }}</dd></div>
                    <div><dt class="text-gray-500">Net Amount</dt><dd class="font-bold">{{ number_format($settlement->net_amount, 2) }}</dd></div>
                    <div><dt class="text-gray-500">Item Count</dt><dd>{{ $settlement->item_count }}</dd></div>
                    <div><dt class="text-gray-500">Payment Gateway</dt><dd>{{ $settlement->payment_gateway ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Gateway Ref</dt><dd class="font-mono text-xs">{{ $settlement->gateway_transfer_ref ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Processed At</dt><dd>{{ $settlement->processed_at ? \Carbon\Carbon::parse($settlement->processed_at)->format('Y-m-d H:i') : '-' }}</dd></div>
                </dl>
                @if ($settlement->notes)
                <div class="mt-4">
                    <dt class="text-gray-500 text-sm">Notes</dt>
                    <dd class="text-sm mt-1">{{ $settlement->notes }}</dd>
                </div>
                @endif
            </div>
        </div>
        <div>
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Summary</h2>
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-600">{{ number_format($settlement->net_amount, 2) }}</div>
                    <div class="text-sm text-gray-500">Net Payout</div>
                </div>
                <div class="mt-4 flex justify-between text-sm">
                    <span class="text-gray-500">Items:</span>
                    <span class="font-medium">{{ $settlement->item_count }}</span>
                </div>
                <div class="flex justify-between text-sm mt-1">
                    <span class="text-gray-500">Gross:</span>
                    <span class="font-medium">{{ number_format($settlement->total_amount, 2) }}</span>
                </div>
                <div class="flex justify-between text-sm mt-1">
                    <span class="text-gray-500">Fees:</span>
                    <span class="font-medium text-red-600">-{{ number_format($settlement->total_commission, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="text-lg font-bold">Settlement Items ({{ $settlement->items->count() }})</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">Transaction Code</th>
                        <th class="px-6 py-3">Transaction Amount</th>
                        <th class="px-6 py-3">Commission</th>
                        <th class="px-6 py-3">Net Amount</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
@forelse ($settlement->items as $item)
                    <tr class="hover:bg-gray-50 text-sm">
                        <td class="px-6 py-3 font-mono">{{ $item->transaction->transcode ?? '#' . $item->transaction_id }}</td>
                        <td class="px-6 py-3">{{ number_format($item->transaction_amount, 2) }}</td>
                        <td class="px-6 py-3">{{ number_format($item->commission_amount, 2) }}</td>
                        <td class="px-6 py-3 font-medium">{{ number_format($item->net_amount, 2) }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 text-xs rounded {{ $item->status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ ucfirst($item->status) }}</span>
                        </td>
                        <td class="px-6 py-3 text-gray-500">{{ $item->notes ?? '-' }}</td>
                    </tr>
@empty
                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No items in this settlement.</td></tr>
@endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
