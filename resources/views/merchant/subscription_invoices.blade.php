@extends('merchant.layout')
@section('title', 'Subscription Invoices')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Invoices</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.subscriptions') }}" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Back to Subscriptions</a>
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-xl font-bold mb-2">{{ $subscription->plan->name ?? 'Deleted Plan' }}</h2>
        <p class="text-gray-600">
            <span class="font-mono">{{ $subscription->customer_email }}</span>
            &middot; Status: <span class="font-semibold capitalize">{{ $subscription->status }}</span>
            &middot; Billing: {{ $subscription->billing_count }} times
        </p>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Invoice #</th>
                        <th class="pb-2">Period</th>
                        <th class="pb-2">Amount</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Due Date</th>
                        <th class="pb-2">Paid At</th>
                        <th class="pb-2">Transaction</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-mono text-sm">{{ $invoice->invoice_number }}</td>
                        <td class="py-3">{{ $invoice->billing_period }}</td>
                        <td class="py-3 font-semibold">{{ number_format($invoice->amount, 2) }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $invoice->status === 'paid' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $invoice->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                {{ $invoice->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $invoice->status === 'cancelled' ? 'bg-gray-100 text-gray-700' : '' }}
                            ">{{ ucfirst($invoice->status) }}</span>
                        </td>
                        <td class="py-3 text-sm">{{ $invoice->due_date->format('Y-m-d') }}</td>
                        <td class="py-3 text-sm">{{ $invoice->paid_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="py-3 font-mono text-sm">
                            @if ($invoice->transaction)
                                {{ $invoice->transaction->transcode }}
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-500">No invoices yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($invoices->hasPages())
        <div class="mt-4">{{ $invoices->links() }}</div>
        @endif
    </div>
</div>
@endsection
