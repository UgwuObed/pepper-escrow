@extends('merchant.layout')
@section('title', 'Wallets')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Wallets</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settings</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Available</h2>
            <p class="text-3xl font-bold text-green-600">{{ number_format($summary['total_balance'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total Ledger</h2>
            <p class="text-3xl font-bold">{{ number_format($summary['total_ledger'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">On Hold</h2>
            <p class="text-3xl font-bold text-yellow-600">{{ number_format($summary['total_hold'], 2) }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Wallets</h2>
            <p class="text-3xl font-bold">{{ $summary['wallet_count'] }}</p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">All Wallets</h2>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">User</th>
                        <th class="pb-2">Currency</th>
                        <th class="pb-2">Type</th>
                        <th class="pb-2">Available</th>
                        <th class="pb-2">Ledger</th>
                        <th class="pb-2">Hold</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($wallets as $wallet)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 font-mono text-sm">{{ $wallet->user_identifier }}</td>
                        <td class="py-3">{{ $wallet->currency }}</td>
                        <td class="py-3"><span class="capitalize">{{ $wallet->type }}</span></td>
                        <td class="py-3 font-semibold">{{ number_format($wallet->balance, 2) }}</td>
                        <td class="py-3">{{ number_format($wallet->ledger_balance, 2) }}</td>
                        <td class="py-3 text-yellow-600">{{ number_format($wallet->hold_balance, 2) }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm {{ $wallet->status ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $wallet->status ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="py-3">
                            <a href="{{ route('merchant.wallet.transactions', $wallet->id) }}" class="text-blue-600 hover:underline text-sm">History</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="py-6 text-center text-gray-500">No wallets yet. Create one via API.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
