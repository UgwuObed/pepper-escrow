@extends('merchant.layout')
@section('title', 'Commission Rules')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Commission Rules</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.transaction-types') }}" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Transaction Types</a>
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.settings') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Settings</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Create Form --}}
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">New Commission Rule</h2>
            <form method="POST" action="{{ route('merchant.commission-rules.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                    <select name="transaction_type_id" required class="w-full border rounded px-3 py-2">
                        <option value="">-- Select --</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name (optional)</label>
                    <input type="text" name="name" class="w-full border rounded px-3 py-2" placeholder="e.g. Standard 2.5%">
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate Type</label>
                        <select name="rate_type" required class="w-full border rounded px-3 py-2">
                            <option value="percentage">Percentage</option>
                            <option value="flat">Flat</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate Value</label>
                        <input type="number" step="0.01" name="rate_value" required class="w-full border rounded px-3 py-2" placeholder="2.5">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cap Amount (0 = no cap)</label>
                    <input type="number" step="0.01" name="cap_amount" class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Min Amount</label>
                        <input type="number" step="0.01" name="min_amount" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Amount</label>
                        <input type="number" step="0.01" name="max_amount" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <input type="number" name="priority" value="0" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payer</label>
                        <select name="payer" class="w-full border rounded px-3 py-2">
                            <option value="merchant">Merchant</option>
                            <option value="customer">Customer</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">Create Rule</button>
            </form>
        </div>

        {{-- Rules List --}}
        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">All Rules</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="text-left text-gray-600 border-b">
                            <th class="pb-2">Type</th>
                            <th class="pb-2">Name</th>
                            <th class="pb-2">Rate</th>
                            <th class="pb-2">Cap</th>
                            <th class="pb-2">Amount Range</th>
                            <th class="pb-2">Priority</th>
                            <th class="pb-2">Payer</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rules as $rule)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3">{{ $rule->transactionType?->name ?? 'N/A' }}</td>
                            <td class="py-3">{{ $rule->name ?? '—' }}</td>
                            <td class="py-3 font-mono">
                                {{ $rule->rate_type === 'percentage' ? $rule->rate_value . '%' : number_format($rule->rate_value, 2) }}
                            </td>
                            <td class="py-3">{{ $rule->cap_amount ? number_format($rule->cap_amount, 2) : '∞' }}</td>
                            <td class="py-3 text-sm">
                                {{ $rule->min_amount ? number_format($rule->min_amount, 2) : '0' }}
                                –
                                {{ $rule->max_amount ? number_format($rule->max_amount, 2) : '∞' }}
                            </td>
                            <td class="py-3">{{ $rule->priority }}</td>
                            <td class="py-3 capitalize">{{ $rule->payer ?? 'merchant' }}</td>
                            <td class="py-3">
                                <form method="POST" action="{{ route('merchant.commission-rules.destroy', $rule->id) }}" onsubmit="return confirm('Delete this rule?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline text-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="py-6 text-center text-gray-500">No commission rules yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
