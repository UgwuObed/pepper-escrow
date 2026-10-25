@extends('admin.layout')
@section('title', 'Reconciliation')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-2">
        <h1 class="text-2xl font-bold">Reconciliation</h1>
        <form method="GET" class="flex gap-2">
            <select name="merchant_id" class="px-3 py-2 border rounded text-sm">
                <option value="">Choose a merchant…</option>
@foreach ($merchants as $m)
                <option value="{{ $m->id }}" {{ $merchantId == $m->id ? 'selected' : '' }}>{{ $m->business_name }}</option>
@endforeach
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Run</button>
            <a href="{{ route('admin.holds') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Held funds</a>
        </form>
    </div>
    <p class="text-sm text-gray-500 mb-6">
        Compares what Peppa recorded as released against what Stripe actually paid out. Read-only:
        it reports drift and never corrects a balance, because the right correction depends on which side is wrong.
    </p>

@if($error)
    <div class="p-4 bg-red-50 border border-red-200 rounded text-red-800">{{ $error }}</div>
@elseif(!$report)
    <div class="p-8 bg-white rounded-lg shadow text-center text-gray-500">Pick a merchant and run the check.</div>
@else
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Holds checked</div>
            <div class="text-2xl font-bold">{{ $report['checked'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Still held</div>
            <div class="text-2xl font-bold">{{ number_format($report['totals']['held'], 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Released (ours)</div>
            <div class="text-2xl font-bold">{{ number_format($report['totals']['released'], 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Paid out (Stripe)</div>
            <div class="text-2xl font-bold">{{ number_format($report['totals']['paid_out'], 2) }}</div>
        </div>
    </div>

@if(count($report['discrepancies']) === 0)
    <div class="p-4 bg-green-50 border border-green-200 rounded text-green-800">{{ $report['summary'] }}</div>
@else
    <div class="mb-4 p-4 bg-orange-50 border border-orange-200 rounded text-orange-800">{{ $report['summary'] }}</div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr class="text-left text-sm text-gray-600">
                    <th class="px-6 py-3">Type</th>
                    <th class="px-6 py-3">Reference</th>
                    <th class="px-6 py-3">Ours</th>
                    <th class="px-6 py-3">Stripe</th>
                    <th class="px-6 py-3">Difference</th>
                    <th class="px-6 py-3">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm">
@foreach ($report['discrepancies'] as $d)
@php
    $badge = match ($d['type']) {
        'over_paid' => 'bg-red-100 text-red-800',
        'orphan_payout' => 'bg-red-100 text-red-800',
        'stale_hold' => 'bg-orange-100 text-orange-800',
        'account_drifted' => 'bg-orange-100 text-orange-800',
        'unreachable' => 'bg-gray-200 text-gray-700',
        default => 'bg-yellow-100 text-yellow-800',
    };
@endphp
                <tr>
                    <td class="px-6 py-3"><span class="px-2 py-1 rounded text-xs {{ $badge }}">{{ str_replace('_', ' ', $d['type']) }}</span></td>
                    <td class="px-6 py-3 font-mono text-xs">
@if(!empty($d['hold_id']))
                        <a href="{{ route('admin.hold.detail', $d['hold_id']) }}" class="text-blue-600 hover:underline">{{ $d['reference'] ?? '—' }}</a>
@else
                        {{ $d['reference'] ?? $d['payout_id'] ?? $d['account_id'] ?? '—' }}
@endif
                    </td>
                    <td class="px-6 py-3">{{ isset($d['ours']) ? number_format($d['ours'], 2) : '—' }}</td>
                    <td class="px-6 py-3">{{ isset($d['stripe']) ? number_format($d['stripe'], 2) : (isset($d['amount']) ? number_format($d['amount'], 2) : '—') }}</td>
                    <td class="px-6 py-3 font-semibold {{ ($d['delta'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-700' }}">
                        {{ isset($d['delta']) ? number_format($d['delta'], 2) : '—' }}
                    </td>
                    <td class="px-6 py-3 text-gray-600">{{ $d['detail'] ?? '' }}</td>
                </tr>
@endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500 mt-4">
        <strong>Over paid</strong> and <strong>orphan payout</strong> mean Stripe moved money Peppa did not record,
        which usually means a manual payout in the Stripe dashboard. Both need looking at before anything else.
    </p>
@endif
@endif
</div>
@endsection
