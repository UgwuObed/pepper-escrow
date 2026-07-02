@extends('merchant.layout')
@section('title', 'Settlements')
@section('content')
<h1 class="text-2xl font-bold mb-6">Settlements</h1>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        @php
            $totals = $settlements->getCollection()->reduce(function ($carry, $s) {
                $carry['total'] += (float) $s->total_amount;
                $carry['commission'] += (float) $s->total_commission;
                $carry['net'] += (float) $s->net_amount;
                return $carry;
            }, ['total' => 0, 'commission' => 0, 'net' => 0]);
        @endphp
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Settled</h2>
            <p class="text-3xl font-bold">{{ number_format($totals['total'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Commission</h2>
            <p class="text-3xl font-bold">{{ number_format($totals['commission'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Net Payout</h2>
            <p class="text-3xl font-bold text-green-600">{{ number_format($totals['net'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Batches</h2>
            <p class="text-3xl font-bold">{{ $settlements->total() }}</p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Settlement Batches</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Batch #</th>
                        <th class="pb-2">Items</th>
                        <th class="pb-2">Total</th>
                        <th class="pb-2">Commission</th>
                        <th class="pb-2">Net</th>
                        <th class="pb-2">Gateway</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Date</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settlements as $s)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-mono text-sm">{{ $s->batch_number }}</td>
                        <td class="py-3">{{ $s->items_count }}</td>
                        <td class="py-3">{{ number_format($s->total_amount, 2) }}</td>
                        <td class="py-3 text-red-600">{{ number_format($s->total_commission, 2) }}</td>
                        <td class="py-3 font-semibold">{{ number_format($s->net_amount, 2) }}</td>
                        <td class="py-3 capitalize">{{ $s->payment_gateway ?? '—' }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $s->status === 'completed' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $s->status === 'processing' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $s->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                {{ $s->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $s->status === 'partially_completed' ? 'bg-orange-100 text-orange-700' : '' }}
                            ">{{ ucfirst(str_replace('_', ' ', $s->status)) }}</span>
                        </td>
                        <td class="py-3 text-sm">{{ $s->created_at->format('Y-m-d') }}</td>
                        <td class="py-3">
                            <a href="{{ route('merchant.settlement.detail', $s->id) }}" class="text-blue-600 hover:underline text-sm">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="py-6 text-center text-gray-500">No settlements yet. Settle via API or schedule.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($settlements->hasPages())
        <div class="mt-4">{{ $settlements->links() }}</div>
        @endif
    </div>
@endsection
