@extends('merchant.layout')
@section('title', 'API Keys')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">API Keys</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settings</a>
            <a href="{{ route('merchant.bank-accounts') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Bank Accounts</a>
            <a href="{{ route('merchant.advanced-settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Advanced</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Credentials</h2>
        <div class="bg-gray-50 p-4 rounded border space-y-4">
            <div>
                <span class="text-sm text-gray-500 font-semibold">App ID:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded select-all">{{ $apiToken->app_id }}</code>
            </div>
            <div>
                <span class="text-sm text-gray-500 font-semibold">API Key:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded select-all">{{ $apiToken->api_key }}</code>
                <button onclick="copyToClipboard('{{ $apiToken->api_key }}')" class="ml-2 text-xs text-blue-600 hover:underline">copy</button>
            </div>
            <div>
                <span class="text-sm text-gray-500 font-semibold">API Secret:</span>
                <code class="ml-2 font-mono text-sm bg-gray-200 px-2 py-1 rounded" id="fullSecret">{{ Str::mask($apiToken->api_secret, '*', 4) }}</code>
                <button onclick="toggleSecret()" id="toggleSecretBtn" class="ml-2 text-xs text-blue-600 hover:underline">show</button>
                <button onclick="copyToClipboard('{{ $apiToken->api_secret }}')" class="ml-2 text-xs text-blue-600 hover:underline">copy</button>
            </div>
            <div>
                <span class="text-sm text-gray-500 font-semibold">Payment Gateway:</span>
                <span class="ml-2 px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm capitalize">{{ $apiToken->payment_gateway }}</span>
            </div>
            <div>
                <span class="text-sm text-gray-500 font-semibold">Status:</span>
                <span class="ml-2 px-2 py-1 rounded text-sm {{ $apiToken->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ ucfirst($apiToken->status) }}</span>
            </div>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4 text-red-600">Regenerate Keys</h2>
        <p class="text-sm text-gray-600 mb-4">Regenerating will invalidate your current keys immediately. API calls using old keys will fail.</p>
        <form method="POST" action="{{ route('merchant.regenerate-keys') }}" onsubmit="return confirm('Are you sure? This will invalidate your current API keys.');">
            @csrf
            <div class="flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="password">Enter your password to confirm</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password" name="password" type="password" required>
                </div>
                <button class="bg-red-600 text-white font-bold py-2 px-6 rounded hover:bg-red-700" type="submit">Regenerate</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSecret() {
    const el = document.getElementById('fullSecret');
    const btn = document.getElementById('toggleSecretBtn');
    if (el.dataset.revealed === 'true') {
        el.textContent = '{{ Str::mask($apiToken->api_secret, "*", 4) }}';
        btn.textContent = 'show';
        el.dataset.revealed = 'false';
    } else {
        el.textContent = '{{ $apiToken->api_secret }}';
        btn.textContent = 'hide';
        el.dataset.revealed = 'true';
    }
}
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => alert('Copied!'));
}
</script>
@endsection
