@extends('merchant.layout')
@section('title', 'Wallet Transactions')
@section('content')
<h1 class="text-2xl font-bold mb-2">Wallet Transactions</h1>
    <p class="text-gray-500 mb-6">User: <code class="font-mono">{{ $wallet->user_identifier }}</code> &middot; {{ $wallet->currency }} &middot; {{ $wallet->type }}</p>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <span class="text-sm text-gray-500">Available</span>
            <p class="text-2xl font-bold text-green-600">{{ number_format($wallet->balance, 2) }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <span class="text-sm text-gray-500">Ledger</span>
            <p class="text-2xl font-bold">{{ number_format($wallet->ledger_balance, 2) }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <span class="text-sm text-gray-500">Hold</span>
            <p class="text-2xl font-bold text-yellow-600">{{ number_format($wallet->hold_balance, 2) }}</p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Transaction History</h2>
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Type</th>
                        <th class="pb-2">Amount</th>
                        <th class="pb-2">Before</th>
                        <th class="pb-2">After</th>
                        <th class="pb-2">Reference</th>
                        <th class="pb-2">Description</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $txn)
                    <tr class="border-b hover:bg-gray-50 text-sm">
                        <td class="py-3">{{ $txn->created_at ? \Carbon\Carbon::parse($txn->created_at)->format('Y-m-d H:i') : '-' }}</td>
                        <td class="py-3">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold
                                @switch($txn->type)
                                    @case('credit') bg-green-100 text-green-700 @break
                                    @case('debit') bg-red-100 text-red-700 @break
                                    @case('hold') bg-yellow-100 text-yellow-700 @break
                                    @case('release_hold') bg-blue-100 text-blue-700 @break
                                    @case('reversal') bg-purple-100 text-purple-700 @break
                                    @default bg-gray-100 text-gray-700
                                @endswitch
                            ">{{ ucfirst(str_replace('_', ' ', $txn->type)) }}</span>
                        </td>
                        <td class="py-3 font-semibold {{ in_array($txn->type, ['credit']) ? 'text-green-600' : (in_array($txn->type, ['debit']) ? 'text-red-600' : '') }}">
                            {{ $txn->type === 'credit' ? '+' : '-' }}{{ number_format($txn->amount, 2) }}
                        </td>
                        <td class="py-3">{{ number_format($txn->balance_before, 2) }}</td>
                        <td class="py-3">{{ number_format($txn->balance_after, 2) }}</td>
                        <td class="py-3 font-mono text-xs">{{ $txn->reference_id ?: '-' }}</td>
                        <td class="py-3 text-gray-500 max-w-xs truncate">{{ $txn->description ?: '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-500">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $transactions->links() }}
        </div>
    </div>
@endsection
