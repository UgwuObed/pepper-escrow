@extends('merchant.layout')
@section('title', 'Dashboard')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold">{{ $merchant->business_name }}</h1>
            <p class="text-gray-500">Merchant Dashboard</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.api-keys') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">API Keys</a>
            <a href="{{ route('merchant.transactions') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Transactions</a>
            <a href="{{ route('merchant.wallets') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Wallets</a>
            <a href="{{ route('merchant.subscription-plans') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Plans</a>
            <a href="{{ route('merchant.subscriptions') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Subscriptions</a>
            <a href="{{ route('merchant.settlements') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settlements</a>
            <a href="{{ route('merchant.revenue') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Revenue</a>
            <a href="{{ route('merchant.reward-programs') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Rewards</a>
            <a href="{{ route('merchant.listing-fees') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Listing Fees</a>
            <a href="{{ route('merchant.notifications') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Notifications</a>
            <a href="{{ route('merchant.virtual-accounts') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Bank Transfer</a>
            <a href="{{ route('merchant.transaction-types') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Types</a>
            <a href="{{ route('merchant.commission-rules') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Fees</a>
            <a href="{{ route('merchant.settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settings</a>
            <a href="{{ route('merchant.bank-accounts') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Bank Accounts</a>
            <a href="{{ route('merchant.advanced-settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Advanced</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Total</h2>
            <p class="text-3xl font-bold">{{ $stats['total_transactions'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Released</h2>
            <p class="text-3xl font-bold text-green-600">{{ $stats['released'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Open</h2>
            <p class="text-3xl font-bold text-yellow-600">{{ $stats['open'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-600">Disputed</h2>
            <p class="text-3xl font-bold text-red-600">{{ $stats['disputed'] }}</p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Your API Credentials</h2>
        <div class="bg-gray-50 p-4 rounded border mb-4">
            <div class="mb-3">
                <span class="text-sm text-gray-500">App ID:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded">{{ $apiToken->app_id }}</code>
            </div>
            <div class="mb-3">
                <span class="text-sm text-gray-500">API Key:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded" id="apiKey">{{ $apiToken->api_key }}</code>
                <button onclick="toggleVisibility('apiKey')" class="ml-2 text-xs text-blue-600 hover:underline">show</button>
            </div>
            <div>
                <span class="text-sm text-gray-500">API Secret:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded" id="apiSecret">{{ Str::mask($apiToken->api_secret, '*', 4) }}</code>
                <button onclick="toggleVisibility('apiSecret', '{{ $apiToken->api_secret }}')" class="ml-2 text-xs text-blue-600 hover:underline">show</button>
            </div>
        </div>
        <a href="{{ route('merchant.api-keys') }}" class="text-blue-600 hover:underline text-sm">Manage API Keys →</a>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Recent Transactions</h2>
            <a href="{{ route('merchant.transactions') }}" class="text-blue-600 hover:underline text-sm">View All →</a>
        </div>
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-2">Code</th>
                    <th class="pb-2">Amount</th>
                    <th class="pb-2">Status</th>
                    <th class="pb-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentTransactions as $txn)
                <tr class="border-b">
                    <td class="py-2 font-mono text-sm">{{ $txn->transcode }}</td>
                    <td class="py-2">{{ number_format($txn->amount, 2) }}</td>
                    <td class="py-2"><span class="px-2 py-1 rounded text-sm {{ $txn->status === 'released' ? 'bg-green-100 text-green-700' : ($txn->status === 'Disputed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $txn->status ?? $txn->trans_status }}</span></td>
                    <td class="py-2">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-4 text-center text-gray-500">No transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleVisibility(elementId, secretValue) {
    const el = document.getElementById(elementId);
    if (el.dataset.revealed === 'true') {
        el.textContent = secretValue ? '••••••••' : el.dataset.original || el.textContent.substring(0, 8) + '••••';
        el.dataset.revealed = 'false';
    } else {
        if (!el.dataset.original) el.dataset.original = el.textContent;
        el.textContent = secretValue || el.dataset.original;
        el.dataset.revealed = 'true';
    }
}
</script>
@endsection
