@extends('merchant.layout')
@section('title', 'Dashboard')
@section('content')
    <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Total</h2>
            <p class="text-3xl font-bold mt-1">{{ $stats['total_transactions'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Released</h2>
            <p class="text-3xl font-bold mt-1 text-green-600">{{ $stats['released'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Open</h2>
            <p class="text-3xl font-bold mt-1 text-yellow-600">{{ $stats['open'] }}</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Disputed</h2>
            <p class="text-3xl font-bold mt-1 text-red-600">{{ $stats['disputed'] }}</p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-lg font-bold mb-4">Your API Credentials</h2>
        <div class="bg-gray-50 p-4 rounded border">
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
        <a href="{{ route('merchant.api-keys') }}" class="text-blue-600 hover:underline text-sm mt-3 inline-block">Manage API Keys &rarr;</a>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold">Recent Transactions</h2>
            <a href="{{ route('merchant.transactions') }}" class="text-blue-600 hover:underline text-sm">View All &rarr;</a>
        </div>
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-3 text-xs uppercase tracking-wide">Code</th>
                    <th class="pb-3 text-xs uppercase tracking-wide">Amount</th>
                    <th class="pb-3 text-xs uppercase tracking-wide">Status</th>
                    <th class="pb-3 text-xs uppercase tracking-wide">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentTransactions as $txn)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-3 font-mono text-sm">{{ $txn->transcode }}</td>
                    <td class="py-3">{{ number_format($txn->amount, 2) }}</td>
                    <td class="py-3">
                        @php $s = $txn->trans_status ?? $txn->status ?? ''; @endphp
                        <span class="px-2 py-1 rounded text-xs font-medium
                            {{ strtolower($s) === 'released' ? 'bg-green-100 text-green-700' : '' }}
                            {{ strtolower($s) === 'fulfilled' ? 'bg-blue-100 text-blue-700' : '' }}
                            {{ strtolower($s) === 'disputed' ? 'bg-red-100 text-red-700' : '' }}
                            {{ in_array(strtolower($s), ['open', 'pending', 'paymentpending']) ? 'bg-yellow-100 text-yellow-700' : '' }}
                        ">{{ $s }}</span>
                    </td>
                    <td class="py-3 text-sm">{{ $txn->posting_date ? \Carbon\Carbon::parse($txn->posting_date)->format('Y-m-d') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-4 text-center text-gray-500">No transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
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
@endpush