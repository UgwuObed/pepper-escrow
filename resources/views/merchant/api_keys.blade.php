@extends('merchant.layout')
@section('title', 'API Keys')
@section('content')
<h1 class="text-2xl font-bold mb-6">API Keys</h1>

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


<div class="bg-white p-6 rounded-lg shadow mt-6">
    <h2 class="text-xl font-bold mb-1">Seller sign-up link</h2>
    <p class="text-sm text-gray-500 mb-4">
        A page your sellers can use to set themselves up for payouts. Share it in an email or a help
        article. Anyone with the link can start, so only switch it on when you are ready.
    </p>

@if (session('status'))
    <div class="mb-4 px-3 py-2 bg-green-50 text-green-800 rounded text-sm">{{ session('status') }}</div>
@endif

@if ($merchant->seller_onboarding_enabled)
    <div class="mb-3">
        <label class="block text-gray-700 text-sm font-bold mb-2">Your link</label>
        <div class="flex gap-2">
            <input class="w-full px-3 py-2 border border-gray-300 rounded font-mono text-sm bg-gray-50"
                   id="sellerLink" type="text" readonly value="{{ $merchant->sellerOnboardingUrl() }}">
            <button type="button" onclick="copyToClipboard(document.getElementById('sellerLink').value)"
                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300 whitespace-nowrap">Copy</button>
        </div>
    </div>
    <div class="flex gap-2">
        <form method="POST" action="{{ route('merchant.seller-onboarding.toggle') }}">
            @csrf
            <input type="hidden" name="enable" value="0">
            <button class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300" type="submit">Switch off</button>
        </form>
        <form method="POST" action="{{ route('merchant.seller-onboarding.rotate') }}"
              onsubmit="return confirm('The current link will stop working immediately. Continue?')">
            @csrf
            <button class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300" type="submit">Replace link</button>
        </form>
    </div>
@else
    <form method="POST" action="{{ route('merchant.seller-onboarding.toggle') }}">
        @csrf
        <input type="hidden" name="enable" value="1">
        <button class="px-4 py-2 bg-blue-600 text-white font-bold rounded text-sm hover:bg-blue-700" type="submit">
            Turn on seller sign-up
        </button>
    </form>
@endif
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
