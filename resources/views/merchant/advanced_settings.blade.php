@extends('merchant.layout')
@section('title', 'Advanced Settings')
@section('content')
<div class="p-6 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">Advanced Settings</h1>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Escrow & Settlement Configuration</h2>
        <form method="POST" action="{{ route('merchant.update-advanced-settings') }}">
            @csrf

            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="escrow_hold_days">Escrow Hold Period (days)</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="escrow_hold_days" name="escrow_hold_days" type="number" min="1" max="365" value="{{ $clientConfig->escrow_hold_days }}">
                    <p class="text-xs text-gray-500 mt-1">How long funds are held after payment before auto-release.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="settlement_schedule">Settlement Schedule</label>
                    <select class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="settlement_schedule" name="settlement_schedule">
                        <option value="manual" {{ $clientConfig->settlement_schedule === 'manual' ? 'selected' : '' }}>Manual (request-based)</option>
                        <option value="daily" {{ $clientConfig->settlement_schedule === 'daily' ? 'selected' : '' }}>Daily</option>
                        <option value="weekly" {{ $clientConfig->settlement_schedule === 'weekly' ? 'selected' : '' }}>Weekly</option>
                        <option value="monthly" {{ $clientConfig->settlement_schedule === 'monthly' ? 'selected' : '' }}>Monthly</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="settlement_day">Settlement Day</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="settlement_day" name="settlement_day" type="number" min="1" max="31" value="{{ $clientConfig->settlement_day }}" placeholder="e.g. 1 for Monday or 1st">
                    <p class="text-xs text-gray-500 mt-1">Day of week (1=Mon) or month (1-31). Used for weekly/monthly schedules.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="min_settlement_amount">Minimum Settlement Amount</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="min_settlement_amount" name="min_settlement_amount" type="number" step="0.01" min="0" value="{{ $clientConfig->min_settlement_amount }}">
                    <p class="text-xs text-gray-500 mt-1">Minimum balance before settlement is triggered.</p>
                </div>
            </div>

            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="auto_release_enabled" value="1" {{ $clientConfig->auto_release_enabled ? 'checked' : '' }} class="mr-2">
                    <span class="text-sm text-gray-700 font-bold">Auto-release escrow on fulfillment</span>
                </label>
                <p class="text-xs text-gray-500 ml-6">Funds are automatically released to the merchant when the buyer confirms fulfillment.</p>
            </div>

            <div class="mb-6">
                <label class="flex items-center">
                    <input type="checkbox" name="require_fulfillment_confirmation" value="1" {{ $clientConfig->require_fulfillment_confirmation ? 'checked' : '' }} class="mr-2">
                    <span class="text-sm text-gray-700 font-bold">Require buyer confirmation before release</span>
                </label>
                <p class="text-xs text-gray-500 ml-6">Funds won't release until the buyer explicitly confirms goods/services were received.</p>
            </div>

            <button class="bg-blue-600 text-white font-bold py-2 px-6 rounded hover:bg-blue-700" type="submit">Save Settings</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Current Configuration</h2>
        <div class="bg-gray-50 p-4 rounded border space-y-2 text-sm">
            <div><span class="font-semibold">Escrow Hold Days:</span> {{ $clientConfig->escrow_hold_days }}</div>
            <div><span class="font-semibold">Settlement Schedule:</span> {{ ucfirst($clientConfig->settlement_schedule) }}</div>
            <div><span class="font-semibold">Min Settlement Amount:</span> {{ number_format($clientConfig->min_settlement_amount, 2) }}</div>
            <div><span class="font-semibold">Auto-release:</span> {{ $clientConfig->auto_release_enabled ? 'Enabled' : 'Disabled' }}</div>
            <div><span class="font-semibold">Require Confirmation:</span> {{ $clientConfig->require_fulfillment_confirmation ? 'Yes' : 'No' }}</div>
            <div><span class="font-semibold">Transaction Types:</span> {{ implode(', ', $clientConfig->allowed_transaction_types ?? ['escrow']) }}</div>
        </div>
    </div>
</div>
@endsection
