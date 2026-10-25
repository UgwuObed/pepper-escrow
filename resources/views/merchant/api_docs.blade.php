@extends('merchant.layout')
@section('title', 'API Documentation')
@section('content')

<h1 class="text-2xl font-bold mb-1">API Documentation</h1>
<p class="text-gray-500 mb-6">Hold a payment in your seller's account and release it when your condition is met. Every example below uses your own key.</p>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">How it works</h2>
    <p class="text-sm text-gray-700 mb-3">
        When a buyer pays, the money goes into the <strong>seller's own account</strong>, not yours and not Peppa's.
        That account is set so nothing reaches the seller's bank until you say so. You hold the release switch,
        never the money.
    </p>
    <p class="text-sm text-gray-700">
        Because the money settles with the seller, card fees, refunds and chargebacks come out of their balance.
        Your fee is taken from each payment as it arrives.
    </p>
</div>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">Your credentials</h2>
    <div class="space-y-3">
        <div>
            <div class="text-xs text-gray-500 font-semibold uppercase mb-1">Base URL</div>
            <div class="flex gap-2">
                <input class="w-full px-3 py-2 border rounded font-mono text-sm bg-gray-50" readonly id="baseUrl" value="{{ url('/') }}">
                <button type="button" onclick="copyField('baseUrl')" class="px-3 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300">Copy</button>
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-500 font-semibold uppercase mb-1">API key</div>
            <div class="flex gap-2">
                <input class="w-full px-3 py-2 border rounded font-mono text-sm bg-gray-50" readonly id="apiKey" type="password" value="{{ $apiToken->api_key }}">
                <button type="button" onclick="toggleKey()" id="keyBtn" class="px-3 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300">Show</button>
                <button type="button" onclick="copyField('apiKey')" class="px-3 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300">Copy</button>
            </div>
            <p class="text-xs text-gray-500 mt-1">Send it as <code class="bg-gray-100 px-1 rounded">Authorization: Bearer &lt;key&gt;</code> on every request.</p>
        </div>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">Test it in Postman</h2>
    <p class="text-sm text-gray-700 mb-3">A ready-made collection covering every endpoint, ordered so each request feeds the next.</p>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('merchant.api-docs.postman') }}" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700">Download collection</a>
        <a href="{{ route('merchant.api-docs.postman-env') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Download environment</a>
    </div>
    <p class="text-xs text-gray-500 mt-2">The environment already has your base URL. Paste your key into it after importing.</p>
</div>

@if($merchant->seller_onboarding_enabled)
<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">Your seller sign-up link</h2>
    <p class="text-sm text-gray-700 mb-3">Send this to sellers so they can set themselves up for payouts.</p>
    <div class="flex gap-2">
        <input class="w-full px-3 py-2 border rounded font-mono text-sm bg-gray-50" readonly id="sellerUrl" value="{{ $merchant->sellerOnboardingUrl() }}">
        <button type="button" onclick="copyField('sellerUrl')" class="px-3 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300">Copy</button>
    </div>
</div>
@endif

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-4">The five calls that matter</h2>

    <h3 class="font-bold text-sm mb-1">1. Create a seller</h3>
    <p class="text-sm text-gray-600 mb-2">Returns a link the seller opens to give Stripe their details and bank account. Nothing you send here reaches their bank — Stripe collects all of it.</p>
    <pre class="bg-gray-900 text-gray-100 p-3 rounded text-xs overflow-x-auto mb-5"><code>curl -X POST {{ url('/api/connected-accounts') }} \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"seller_email":"seller@example.com","country":"GB"}'</code></pre>

    <h3 class="font-bold text-sm mb-1">2. Set a release rule</h3>
    <p class="text-sm text-gray-600 mb-2">What releases your money. Three conditions: <code class="bg-gray-100 px-1 rounded">buyer_confirmation</code>, <code class="bg-gray-100 px-1 rounded">elapsed_days</code>, <code class="bg-gray-100 px-1 rounded">manual_approval</code>. Always have a timer, or a payment nobody confirms sits until the deadline forces it out.</p>
    <pre class="bg-gray-900 text-gray-100 p-3 rounded text-xs overflow-x-auto mb-5"><code>curl -X POST {{ url('/api/settlement-rules') }} \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"Release after 7 days","condition_type":"elapsed_days","days":7}'</code></pre>

    <h3 class="font-bold text-sm mb-1">3. Hold a payment</h3>
    <p class="text-sm text-gray-600 mb-2">Use your own order number as the reference. Calling twice with the same one returns the existing hold, so retries are safe.</p>
    <pre class="bg-gray-900 text-gray-100 p-3 rounded text-xs overflow-x-auto mb-5"><code>curl -X POST {{ url('/api/holds') }} \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"reference":"ORDER-1234","connected_account_id":"acct_...","amount":100.00,"currency":"GBP"}'</code></pre>

    <h3 class="font-bold text-sm mb-1">4. Release it</h3>
    <p class="text-sm text-gray-600 mb-2">The buyer confirms, or someone approves. Timers need no call — they fire on their own.</p>
    <pre class="bg-gray-900 text-gray-100 p-3 rounded text-xs overflow-x-auto mb-5"><code>curl -X POST {{ url('/api/holds/ORDER-1234/confirm') }} \
  -H "Authorization: Bearer YOUR_KEY"</code></pre>

    <h3 class="font-bold text-sm mb-1">5. Or dispute it</h3>
    <p class="text-sm text-gray-600 mb-2">Freezes everything until someone decides. No rule fires and no timer runs while a payment is disputed.</p>
    <pre class="bg-gray-900 text-gray-100 p-3 rounded text-xs overflow-x-auto"><code>curl -X POST {{ url('/api/holds/ORDER-1234/dispute') }} \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Item never arrived"}'</code></pre>
</div>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">Every endpoint</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase border-b">
                    <th class="py-2 pr-4">Method</th><th class="py-2 pr-4">Path</th><th class="py-2">Does</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach ([
                    ['POST', '/api/connected-accounts', 'Create a seller, get an onboarding link'],
                    ['GET', '/api/connected-accounts', 'List your sellers'],
                    ['GET', '/api/connected-accounts/{id}', 'One seller, with can_hold_funds'],
                    ['POST', '/api/connected-accounts/{id}/onboarding-link', 'A fresh link for a seller who stalled'],
                    ['POST', '/api/connected-accounts/{id}/sync', 'Pull the latest state from Stripe'],
                    ['GET', '/api/connected-accounts/{id}/dashboard', 'What the seller sees: balance, payouts, holds'],
                    ['POST', '/api/connected-accounts/{id}/account-session', 'Embed Stripe UI for bank details'],
                    ['POST', '/api/holds', 'Hold a payment'],
                    ['GET', '/api/holds', 'List holds'],
                    ['GET', '/api/holds/{reference}', 'One hold, with its full history'],
                    ['POST', '/api/holds/{reference}/confirm', 'Buyer confirms receipt'],
                    ['POST', '/api/holds/{reference}/approve', 'Someone approves it'],
                    ['POST', '/api/holds/{reference}/dispute', 'Raise a dispute'],
                    ['POST', '/api/holds/{reference}/dispute/resolve', 'Release, refund or resume'],
                    ['POST', '/api/holds/{reference}/refund', 'Refund, full or partial'],
                    ['GET', '/api/settlement-rules', 'List your rules'],
                    ['POST', '/api/settlement-rules', 'Create a rule'],
                    ['PUT', '/api/settlement-rules/{id}', 'Update a rule'],
                    ['DELETE', '/api/settlement-rules/{id}', 'Deactivate a rule'],
                ] as $row)
                <tr>
                    <td class="py-2 pr-4"><span class="font-mono text-xs font-semibold text-blue-700">{{ $row[0] }}</span></td>
                    <td class="py-2 pr-4 font-mono text-xs">{{ $row[1] }}</td>
                    <td class="py-2 text-gray-600">{{ $row[2] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h2 class="text-lg font-bold mb-3">Responses</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 uppercase border-b"><th class="py-2 pr-4">Code</th><th class="py-2">Means</th></tr></thead>
            <tbody class="divide-y">
                <tr><td class="py-2 pr-4 font-mono text-xs">200 / 201</td><td class="py-2 text-gray-600">Done</td></tr>
                <tr><td class="py-2 pr-4 font-mono text-xs">401</td><td class="py-2 text-gray-600">Missing or wrong API key</td></tr>
                <tr><td class="py-2 pr-4 font-mono text-xs">404</td><td class="py-2 text-gray-600">Not found, or not yours</td></tr>
                <tr><td class="py-2 pr-4 font-mono text-xs">409</td><td class="py-2 text-gray-600">The state does not allow it — often a seller who is not ready, or a disputed hold</td></tr>
                <tr><td class="py-2 pr-4 font-mono text-xs">422</td><td class="py-2 text-gray-600">Something in the request is wrong; <code class="bg-gray-100 px-1 rounded">message</code> says what</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-amber-50 border border-amber-200 p-5 rounded-lg mb-6">
    <h2 class="font-bold mb-2 text-amber-900">Things worth knowing before you build</h2>
    <ul class="text-sm text-amber-900 space-y-2 list-disc ml-5">
        <li><strong>A hold has a deadline.</strong> Stripe limits how long money can sit in a seller's account — 90 days in the UK, 2 years in the US. Each hold's <code class="bg-amber-100 px-1 rounded">release_by</code> tells you its date, and the money is released automatically shortly before it whether or not your condition was met.</li>
        <li><strong>A dispute does not pause that clock.</strong> Freezing a release does not extend the deadline, so resolve disputes well inside it.</li>
        <li><strong>Sellers get no Stripe dashboard.</strong> That is what stops them paying themselves out early. Use the dashboard endpoint to show them their balance and payouts in your own product.</li>
        <li><strong>You cannot help a seller through onboarding.</strong> Stripe owns every field — nobody can pre-fill a name or fix a typo for them. A fresh link is the only lever.</li>
        <li><strong>One payment goes to one seller.</strong> Splitting across several has to happen when the buyer pays.</li>
    </ul>
</div>

<script>
function copyField(id) {
    const el = document.getElementById(id);
    const wasHidden = el.type === 'password';
    if (wasHidden) el.type = 'text';
    navigator.clipboard.writeText(el.value).then(function () {
        if (wasHidden) el.type = 'password';
    });
}
function toggleKey() {
    const el = document.getElementById('apiKey');
    const btn = document.getElementById('keyBtn');
    const hidden = el.type === 'password';
    el.type = hidden ? 'text' : 'password';
    btn.textContent = hidden ? 'Hide' : 'Show';
}
</script>

@endsection
