@extends('merchant.layout')
@section('title', 'Subscriptions')
@section('content')
<h1 class="text-2xl font-bold mb-6">Subscriptions</h1>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">All Subscriptions</h2>
            <span class="text-sm text-gray-500">{{ $subscriptions->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Plan</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Next Billing</th>
                        <th class="pb-2">Billed</th>
                        <th class="pb-2">Started</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3">
                            <div class="font-medium">{{ $sub->customer_name }}</div>
                            <div class="text-sm text-gray-500 font-mono">{{ $sub->customer_email }}</div>
                        </td>
                        <td class="py-3">{{ $sub->plan->name ?? 'Deleted' }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $sub->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $sub->status === 'paused' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                {{ $sub->status === 'cancelled' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $sub->status === 'expired' ? 'bg-gray-100 text-gray-700' : '' }}
                            ">{{ ucfirst($sub->status) }}</span>
                        </td>
                        <td class="py-3 text-sm">
                            @if ($sub->next_billing_at && $sub->isActive())
                                {{ $sub->next_billing_at->format('Y-m-d') }}
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3">{{ $sub->billing_count }}</td>
                        <td class="py-3 text-sm">{{ $sub->created_at->format('Y-m-d') }}</td>
                        <td class="py-3">
                            <a href="{{ route('merchant.subscription.invoices', $sub->id) }}" class="text-blue-600 hover:underline text-sm">Invoices</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-500">No subscriptions yet. Create via API.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscriptions->hasPages())
        <div class="mt-4">{{ $subscriptions->links() }}</div>
        @endif
    </div>
@endsection
