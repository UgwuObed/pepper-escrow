@extends('admin.layout')
@section('title', 'Settlements')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Settlements</h1>
        <form method="GET" class="flex gap-2">
            <select name="merchant_id" class="px-3 py-2 border rounded text-sm">
                <option value="">All Merchants</option>
@foreach ($merchants as $m)
                <option value="{{ $m->id }}" {{ request('merchant_id') == $m->id ? 'selected' : '' }}>{{ $m->business_name }}</option>
@endforeach
            </select>
            <select name="status" class="px-3 py-2 border rounded text-sm">
                <option value="">All Status</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Processing</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
@if(request()->hasAny(['merchant_id', 'status']))
            <a href="{{ route('admin.settlements') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
@endif
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr class="text-left text-sm text-gray-600">
                    <th class="px-6 py-3">Batch #</th>
                    <th class="px-6 py-3">Merchant</th>
                    <th class="px-6 py-3">Items</th>
                    <th class="px-6 py-3">Total Amount</th>
                    <th class="px-6 py-3">Commission</th>
                    <th class="px-6 py-3">Net Amount</th>
                    <th class="px-6 py-3">Gateway</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Processed</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
@forelse ($settlements as $s)
                <tr class="hover:bg-gray-50 text-sm">
                    <td class="px-6 py-3 font-mono">{{ $s->batch_number }}</td>
                    <td class="px-6 py-3">{{ $s->merchant->business_name ?? 'N/A' }}</td>
                    <td class="px-6 py-3">{{ $s->item_count }}</td>
                    <td class="px-6 py-3">{{ number_format($s->total_amount, 2) }}</td>
                    <td class="px-6 py-3">{{ number_format($s->total_commission, 2) }}</td>
                    <td class="px-6 py-3 font-medium">{{ number_format($s->net_amount, 2) }}</td>
                    <td class="px-6 py-3">{{ $s->payment_gateway ?? '-' }}</td>
                    <td class="px-6 py-3">
                        <span class="px-2 py-1 text-xs rounded {{ $s->status === 'completed' ? 'bg-green-100 text-green-700' : ($s->status === 'failed' ? 'bg-red-100 text-red-700' : ($s->status === 'processing' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700')) }}">{{ ucfirst($s->status) }}</span>
                    </td>
                    <td class="px-6 py-3 text-gray-500">{{ $s->processed_at ? \Carbon\Carbon::parse($s->processed_at)->format('Y-m-d') : '-' }}</td>
                    <td class="px-6 py-3"><a href="{{ route('admin.settlement.detail', $s->id) }}" class="text-blue-600 hover:underline">View</a></td>
                </tr>
@empty
                <tr><td colspan="10" class="px-6 py-8 text-center text-gray-500">No settlements found.</td></tr>
@endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        {{ $settlements->links() }}
    </div>
</div>
@endsection
