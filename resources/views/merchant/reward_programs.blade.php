@extends('merchant.layout')
@section('title', 'Reward Programs')
@section('content')
<h1 class="text-2xl font-bold mb-6">Reward Programs</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">New Program</h2>
            <form method="POST" action="{{ route('merchant.reward-programs.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reward Type</label>
                    <select name="reward_type" required class="w-full border rounded px-3 py-2">
                        <option value="points">Points</option>
                        <option value="cashback">Cashback (%)</option>
                        <option value="discount_percentage">Discount (%)</option>
                        <option value="discount_flat">Discount (Flat)</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reward Value</label>
                    <input type="number" step="0.01" name="reward_value" required class="w-full border rounded px-3 py-2">
                    <p class="text-xs text-gray-500 mt-1">Points per unit, % for cashback/discount, or flat amount</p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Min Transaction Amount</label>
                    <input type="number" step="0.01" name="min_transaction_amount" class="w-full border rounded px-3 py-2">
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">Create</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">All Programs</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="text-left text-gray-600 border-b">
                            <th class="pb-2">Name</th>
                            <th class="pb-2">Type</th>
                            <th class="pb-2">Value</th>
                            <th class="pb-2">Min Amount</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($programs as $p)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 font-medium">{{ $p->name }}</td>
                            <td class="py-3 capitalize">{{ str_replace('_', ' ', $p->reward_type) }}</td>
                            <td class="py-3">{{ $p->reward_value }}</td>
                            <td class="py-3">{{ $p->min_transaction_amount ? number_format($p->min_transaction_amount, 2) : '—' }}</td>
                            <td class="py-3">
                                <span class="px-2 py-1 rounded text-sm {{ $p->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $p->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="py-3">
                                <form method="POST" action="{{ route('merchant.reward-programs.toggle', $p->id) }}">
                                    @csrf
                                    <button type="submit" class="text-sm {{ $p->is_active ? 'text-yellow-600 hover:underline' : 'text-green-600 hover:underline' }}">
                                        {{ $p->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No reward programs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
