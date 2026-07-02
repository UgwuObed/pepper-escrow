@extends('merchant.layout')
@section('title', 'Virtual Accounts')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Virtual Accounts</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.transactions') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Transactions</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">All Virtual Accounts</h2>
            <span class="text-sm text-gray-500">{{ $accounts->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Account Number</th>
                        <th class="pb-2">Bank</th>
                        <th class="pb-2">Account Name</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Gateway</th>
                        <th class="pb-2">Transaction</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $va)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-mono text-sm font-semibold">{{ $va->account_number }}</td>
                        <td class="py-3">{{ $va->bank_name }}</td>
                        <td class="py-3">{{ $va->account_name }}</td>
                        <td class="py-3 font-mono text-sm">{{ $va->customer_email }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm capitalize {{ $va->gateway === 'paystack' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' }}">
                                {{ $va->gateway }}
                            </span>
                        </td>
                        <td class="py-3 font-mono text-sm">
                            @if ($va->transaction)
                                <a href="#" class="text-blue-600 hover:underline">{{ $va->transaction->transcode }}</a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $va->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $va->status === 'assigned' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $va->status === 'dormant' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                {{ $va->status === 'closed' ? 'bg-red-100 text-red-700' : '' }}
                            ">{{ ucfirst($va->status) }}</span>
                        </td>
                        <td class="py-3 text-sm">{{ $va->created_at->format('Y-m-d') }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="py-6 text-center text-gray-500">No virtual accounts yet. Create one via the bank transfer API.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
        <div class="mt-4">
            {{ $accounts->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
