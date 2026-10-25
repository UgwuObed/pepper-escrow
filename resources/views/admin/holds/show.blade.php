@extends('admin.layout')
@section('title', 'Hold ' . $hold->reference)
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ $hold->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $hold->connectedAccount->seller_email ?? 'seller unknown' }}</p>
        </div>
        <a href="{{ route('admin.holds') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Back</a>
    </div>

@if($hold->isReleasable() && $hold->hasExpired())
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded">
        <strong class="text-red-800">Past the Stripe holding limit.</strong>
        <span class="text-red-700 text-sm">
            This should have been released by {{ $hold->hold_expires_at->toDateString() }}. Holding funds beyond
            the limit for the seller's country is a compliance breach, not a soft deadline.
        </span>
    </div>
@elseif($hold->isFrozen() && $hold->expiresWithin(14))
    <div class="mb-6 p-4 bg-orange-50 border border-orange-200 rounded">
        <strong class="text-orange-800">Disputed, and the clock is still running.</strong>
        <span class="text-orange-700 text-sm">
            A dispute does not pause Stripe's holding limit. This needs resolving before
            {{ $hold->hold_expires_at?->toDateString() }}.
        </span>
    </div>
@endif

    <div class="grid grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="font-semibold mb-4">Money</h2>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between"><dt class="text-gray-500">Amount</dt><dd>{{ $hold->currency }} {{ number_format((float) $hold->amount, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Released</dt><dd>{{ number_format((float) $hold->amount_released, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Refunded</dt><dd>{{ number_format((float) $hold->amount_refunded, 2) }}</dd></div>
                <div class="flex justify-between font-semibold border-t pt-2"><dt>Still held</dt><dd>{{ number_format($hold->remaining(), 2) }}</dd></div>
            </dl>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="font-semibold mb-4">State</h2>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between"><dt class="text-gray-500">Status</dt><dd>{{ str_replace('_', ' ', $hold->status) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Held since</dt><dd>{{ $hold->held_at?->toDateTimeString() ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Release by</dt><dd>{{ $hold->hold_expires_at?->toDateString() ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Released by rule</dt><dd>{{ $hold->rule->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Reason</dt><dd>{{ $hold->release_reason ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Payment intent</dt><dd class="font-mono text-xs">{{ $hold->payment_intent_id ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>

@if($hold->connectedAccount && !$hold->connectedAccount->canHoldFunds())
    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded">
        <strong class="text-yellow-800">This seller account cannot currently be released to.</strong>
        <ul class="text-yellow-700 text-sm list-disc ml-5 mt-1">
@foreach($hold->connectedAccount->holdBlockers() as $blocker)
            <li>{{ $blocker }}</li>
@endforeach
        </ul>
    </div>
@endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <h2 class="font-semibold px-6 py-4 border-b">Why this money moved</h2>
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr class="text-left text-sm text-gray-600">
                    <th class="px-6 py-3">When</th>
                    <th class="px-6 py-3">Event</th>
                    <th class="px-6 py-3">Who</th>
                    <th class="px-6 py-3">Amount</th>
                    <th class="px-6 py-3">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm">
@forelse ($hold->events as $event)
                <tr>
                    <td class="px-6 py-3 whitespace-nowrap">{{ $event->created_at?->toDateTimeString() }}</td>
                    <td class="px-6 py-3">{{ str_replace('_', ' ', $event->event_type) }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $event->actor ?? '—' }}</td>
                    <td class="px-6 py-3">{{ $event->amount !== null ? number_format((float) $event->amount, 2) : '—' }}</td>
                    <td class="px-6 py-3 text-gray-600">
                        {{ $event->note }}
@if($event->detail)
                        <span class="block text-xs text-gray-400 font-mono">{{ json_encode($event->detail) }}</span>
@endif
                    </td>
                </tr>
@empty
                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No events recorded.</td></tr>
@endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
