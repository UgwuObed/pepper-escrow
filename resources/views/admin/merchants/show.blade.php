@extends('admin.layout')
@section('title', $merchant->business_name)
@section('content')
<div class="p-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('admin.merchants') }}" class="text-blue-600 hover:underline">&larr; Back</a>
        <h1 class="text-2xl font-bold">{{ $merchant->business_name }}</h1>
        <span class="px-2 py-1 text-xs rounded {{ $merchant->status ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $merchant->status ? 'Active' : 'Suspended' }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Merchant Details</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Email</dt><dd>{{ $merchant->email }}</dd></div>
                    <div><dt class="text-gray-500">Phone</dt><dd>{{ $merchant->phone ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Website</dt><dd>{{ $merchant->website ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Webhook URL</dt><dd class="truncate">{{ $merchant->webhook_url ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Registered</dt><dd>{{ $merchant->created_at->format('Y-m-d H:i') }}</dd></div>
                </dl>
            </div>

            @if ($config)
            <div class="bg-white rounded-lg shadow p-6 mt-6">
                <h2 class="text-lg font-bold mb-4">Client Configuration</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Escrow Hold Days</dt><dd>{{ $config->escrow_hold_days }}</dd></div>
                    <div><dt class="text-gray-500">Settlement Schedule</dt><dd>{{ $config->settlement_schedule ?? 'manual' }}</dd></div>
                    <div><dt class="text-gray-500">Min Settlement Amount</dt><dd>{{ number_format($config->min_settlement_amount ?? 0, 2) }}</dd></div>
                    <div><dt class="text-gray-500">Auto Release</dt><dd>{{ $config->auto_release_enabled ? 'Yes' : 'No' }}</dd></div>
                    <div><dt class="text-gray-500">Require Confirmation</dt><dd>{{ $config->require_fulfillment_confirmation ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </div>
            @endif

            @if ($wallets->count())
            <div class="bg-white rounded-lg shadow p-6 mt-6">
                <h2 class="text-lg font-bold mb-4">Wallets</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($wallets as $wallet)
                    <div class="border rounded p-4">
                        <div class="text-sm text-gray-500">{{ $wallet->label ?? $wallet->type }} ({{ $wallet->currency }})</div>
                        <div class="text-xl font-bold">{{ number_format($wallet->availableBalance(), 2) }}</div>
                        <div class="text-xs text-gray-400">Ledger: {{ number_format($wallet->ledger_balance, 2) }} | Hold: {{ number_format($wallet->hold_balance, 2) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <div>
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Stats</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-sm text-gray-500">Total Transactions</div>
                        <div class="text-xl font-bold">{{ number_format($transactionCount) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Total Volume</div>
                        <div class="text-xl font-bold">{{ number_format($volume, 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <form method="POST" action="{{ route('admin.merchant.toggle', $merchant->id) }}" onsubmit="return confirm('{{ $merchant->status ? 'Suspend' : 'Activate' }} this merchant?')">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 rounded text-white {{ $merchant->status ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }}">
                        {{ $merchant->status ? 'Suspend Merchant' : 'Activate Merchant' }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="text-lg font-bold">Recent Transactions</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">Code</th>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Amount</th>
                        <th class="px-6 py-3">Commission</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Gateway</th>
                        <th class="px-6 py-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($transactions as $txn)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-mono">{{ $txn->transcode }}</td>
                        <td class="px-6 py-3 text-sm">{{ $txn->customer_email }}</td>
                        <td class="px-6 py-3 text-sm">{{ number_format($txn->amount, 2) }}</td>
                        <td class="px-6 py-3 text-sm">{{ number_format($txn->commission_amount ?? $txn->pepperest_fee ?? 0, 2) }}</td>
                        <td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded {{ $txn->trans_status === 'Released' ? 'bg-green-100 text-green-700' : ($txn->trans_status === 'Fulfilled' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $txn->trans_status }}</span></td>
                        <td class="px-6 py-3 text-sm">{{ $txn->payment_gateway ?? '-' }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
