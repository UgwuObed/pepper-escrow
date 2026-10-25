@extends('admin.layout')
@section('title', 'Held Funds')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Held Funds</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.holds.reconciliation') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Reconciliation</a>
            <form method="GET" class="flex gap-2">
                <select name="merchant_id" class="px-3 py-2 border rounded text-sm">
                    <option value="">All Merchants</option>
@foreach ($merchants as $m)
                    <option value="{{ $m->id }}" {{ request('merchant_id') == $m->id ? 'selected' : '' }}>{{ $m->business_name }}</option>
@endforeach
                </select>
                <select name="status" class="px-3 py-2 border rounded text-sm">
                    <option value="">All Status</option>
@foreach (['held' => 'Held', 'part_released' => 'Partly released', 'released' => 'Released', 'disputed' => 'Disputed', 'refunded' => 'Refunded'] as $value => $label)
                    <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
@endforeach
                </select>
                <label class="flex items-center gap-1 text-sm px-2">
                    <input type="checkbox" name="attention" value="1" {{ request()->boolean('attention') ? 'checked' : '' }}>
                    Needs attention
                </label>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
@if(request()->hasAny(['merchant_id', 'status', 'attention']))
                <a href="{{ route('admin.holds') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
@endif
            </form>
        </div>
    </div>

    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Currently held</div>
            <div class="text-2xl font-bold">{{ number_format((float) $summary['held'], 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Disputed</div>
            <div class="text-2xl font-bold {{ $summary['disputed'] > 0 ? 'text-orange-600' : '' }}">{{ $summary['disputed'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Expiring in 7 days</div>
            <div class="text-2xl font-bold {{ $summary['expiring'] > 0 ? 'text-orange-600' : '' }}">{{ $summary['expiring'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Past Stripe limit</div>
            <div class="text-2xl font-bold {{ $summary['overdue'] > 0 ? 'text-red-600' : '' }}">{{ $summary['overdue'] }}</div>
            @if($summary['overdue'] > 0)
            <div class="text-xs text-red-600 mt-1">Compliance breach, release now</div>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr class="text-left text-sm text-gray-600">
                    <th class="px-6 py-3">Reference</th>
                    <th class="px-6 py-3">Seller</th>
                    <th class="px-6 py-3">Amount</th>
                    <th class="px-6 py-3">Released</th>
                    <th class="px-6 py-3">Remaining</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Release by</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm">
@forelse ($holds as $hold)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 font-mono text-xs">{{ $hold->reference }}</td>
                    <td class="px-6 py-3">
                        {{ $hold->connectedAccount->seller_email ?? '—' }}
                        <span class="text-xs text-gray-400">{{ $hold->connectedAccount->country ?? '' }}</span>
                    </td>
                    <td class="px-6 py-3">{{ $hold->currency }} {{ number_format((float) $hold->amount, 2) }}</td>
                    <td class="px-6 py-3">{{ number_format((float) $hold->amount_released, 2) }}</td>
                    <td class="px-6 py-3 font-semibold">{{ number_format($hold->remaining(), 2) }}</td>
                    <td class="px-6 py-3">
@php
    $badge = match ($hold->status) {
        'released' => 'bg-green-100 text-green-800',
        'part_released' => 'bg-blue-100 text-blue-800',
        'disputed' => 'bg-orange-100 text-orange-800',
        'refunded' => 'bg-gray-200 text-gray-700',
        default => 'bg-yellow-100 text-yellow-800',
    };
@endphp
                        <span class="px-2 py-1 rounded text-xs {{ $badge }}">{{ str_replace('_', ' ', $hold->status) }}</span>
                    </td>
                    <td class="px-6 py-3">
@if($hold->hold_expires_at)
    @if($hold->isReleasable() && $hold->hasExpired())
                        <span class="text-red-600 font-semibold">{{ $hold->hold_expires_at->toDateString() }}</span>
                        <div class="text-xs text-red-600">overdue</div>
    @elseif($hold->isReleasable() && $hold->expiresWithin(7))
                        <span class="text-orange-600">{{ $hold->hold_expires_at->toDateString() }}</span>
    @else
                        <span class="text-gray-600">{{ $hold->hold_expires_at->toDateString() }}</span>
    @endif
@else
                        <span class="text-gray-400">—</span>
@endif
                    </td>
                    <td class="px-6 py-3 text-right">
                        <a href="{{ route('admin.hold.detail', $hold->id) }}" class="text-blue-600 hover:underline">View</a>
                    </td>
                </tr>
@empty
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No held funds match this filter.</td></tr>
@endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $holds->links() }}</div>
</div>
@endsection
