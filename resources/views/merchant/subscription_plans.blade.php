@extends('merchant.layout')
@section('title', 'Subscription Plans')
@section('content')
<h1 class="text-2xl font-bold mb-6">Subscription Plans</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Create Form --}}
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">New Plan</h2>
            <form method="POST" action="{{ route('merchant.subscription-plans.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plan Name</label>
                    <input type="text" name="name" required class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"></textarea>
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" step="0.01" name="amount" required class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                        <input type="text" name="currency" value="NGN" maxlength="3" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Billing Cycle</label>
                        <select name="billing_cycle" required class="w-full border rounded px-3 py-2">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Every N Cycles</label>
                        <input type="number" name="cycle_interval" value="1" min="1" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Trial Days</label>
                    <input type="number" name="trial_days" value="0" min="0" class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type (for commission)</label>
                    <select name="transaction_type_id" class="w-full border rounded px-3 py-2">
                        <option value="">None</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">Create Plan</button>
            </form>
        </div>

        {{-- Plans List --}}
        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">All Plans</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="text-left text-gray-600 border-b">
                            <th class="pb-2">Name</th>
                            <th class="pb-2">Amount</th>
                            <th class="pb-2">Cycle</th>
                            <th class="pb-2">Trial</th>
                            <th class="pb-2">Active Subs</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plans as $plan)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 font-medium">{{ $plan->name }}</td>
                            <td class="py-3">{{ number_format($plan->amount, 2) }} {{ $plan->currency }}</td>
                            <td class="py-3 capitalize">{{ $plan->billing_cycle }} (x{{ $plan->cycle_interval }})</td>
                            <td class="py-3">{{ $plan->trial_days ? $plan->trial_days . ' days' : '—' }}</td>
                            <td class="py-3">{{ $plan->subscriptions->where('status', 'active')->count() }}</td>
                            <td class="py-3">
                                <span class="px-2 py-1 rounded text-sm {{ $plan->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="py-3">
                                <form method="POST" action="{{ route('merchant.subscription-plans.toggle', $plan->id) }}">
                                    @csrf
                                    <button type="submit" class="text-sm {{ $plan->is_active ? 'text-yellow-600 hover:underline' : 'text-green-600 hover:underline' }}">
                                        {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No plans yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
