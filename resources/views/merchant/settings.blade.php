@extends('merchant.layout')
@section('title', 'Settings')
@section('content')
<div class="p-6 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">Settings</h1>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Profile</h2>
        <form method="POST" action="{{ route('merchant.update-profile') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="business_name">Business Name</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="business_name" name="business_name" type="text" value="{{ $merchant->business_name }}" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="email">Email</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="email" name="email" type="email" value="{{ $merchant->email }}" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="phone">Phone</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="phone" name="phone" type="text" value="{{ $merchant->phone }}">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="website">Website</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="website" name="website" type="url" value="{{ $merchant->website }}">
                </div>
            </div>
            <button class="bg-blue-600 text-white font-bold py-2 px-6 rounded hover:bg-blue-700" type="submit">Save Profile</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Integration Settings</h2>
        <form method="POST" action="{{ route('merchant.update-settings') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="webhook_url">Webhook URL</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="webhook_url" name="webhook_url" type="url" value="{{ $merchant->webhook_url }}" placeholder="https://your-app.com/webhook">
                <p class="text-xs text-gray-500 mt-1">Payment notifications will be sent here.</p>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="webhook_secret">Webhook Secret</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="webhook_secret" name="webhook_secret" type="text" value="{{ $clientConfig->webhook_secret }}">
                <p class="text-xs text-gray-500 mt-1">Used to verify webhook signatures. Leave empty to auto-generate.</p>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="payment_gateway">Payment Gateway</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="payment_gateway" name="payment_gateway">
                    <option value="paystack" {{ $apiToken->payment_gateway === 'paystack' ? 'selected' : '' }}>Paystack</option>
                    <option value="stripe" {{ $apiToken->payment_gateway === 'stripe' ? 'selected' : '' }}>Stripe</option>
                    <option value="seerbit" {{ $apiToken->payment_gateway === 'seerbit' ? 'selected' : '' }}>SeerBit</option>
                    <option value="flutterwave" {{ $apiToken->payment_gateway === 'flutterwave' ? 'selected' : '' }}>Flutterwave</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="gateway_config">Gateway Config <span class="text-gray-400 font-normal">(JSON)</span></label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300 font-mono text-sm" id="gateway_config" name="gateway_config" rows="4">{{ json_encode($apiToken->gateway_config ?? [], JSON_PRETTY_PRINT) }}</textarea>
                <p class="text-xs text-gray-500 mt-1">Optional gateway-specific settings (e.g. subaccount code).</p>
            </div>
            <button class="bg-blue-600 text-white font-bold py-2 px-6 rounded hover:bg-blue-700" type="submit">Save Settings</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Change Password</h2>
        <form method="POST" action="{{ route('merchant.update-password') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="current_password">Current Password</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="current_password" name="current_password" type="password" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="password">New Password</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password" name="password" type="password" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="password_confirmation">Confirm New Password</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password_confirmation" name="password_confirmation" type="password" required>
                </div>
            </div>
            <button class="bg-red-600 text-white font-bold py-2 px-6 rounded hover:bg-red-700" type="submit">Change Password</button>
        </form>
    </div>
</div>
@endsection
