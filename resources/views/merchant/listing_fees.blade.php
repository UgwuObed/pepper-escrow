@extends('merchant.layout')
@section('title', 'Listing Fees')
@section('content')
<div class="p-6">
    <h1 class="text-3xl font-bold mb-6">Listing Fees</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">New Listing Fee</h2>
            <form method="POST" action="{{ route('merchant.listing-fees.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"></textarea>
                </div>
                <div class="mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fee Type</label>
                        <select name="fee_type" required class="w-full border rounded px-3 py-2">
                            <option value="flat">Flat</option>
                            <option value="percentage">Percentage</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Value</label>
                        <input type="number" step="0.01" name="fee_value" required class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cap Amount (optional)</label>
                    <input type="number" step="0.01" name="cap_amount" class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type (optional)</label>
                    <select name="transaction_type_id" class="w-full border rounded px-3 py-2">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">Create</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">All Listing Fees</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="text-left text-gray-600 border-b">
                            <th class="pb-2">Name</th>
                            <th class="pb-2">Type</th>
                            <th class="pb-2">Fee</th>
                            <th class="pb-2">Cap</th>
                            <th class="pb-2">Applies To</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($fees as $fee)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 font-medium">{{ $fee->name }}</td>
                            <td class="py-3 capitalize">{{ $fee->fee_type }}</td>
                            <td class="py-3">{{ $fee->fee_type === 'percentage' ? $fee->fee_value . '%' : number_format($fee->fee_value, 2) }}</td>
                            <td class="py-3">{{ $fee->cap_amount ? number_format($fee->cap_amount, 2) : '∞' }}</td>
                            <td class="py-3">{{ $fee->transactionType?->name ?? 'All types' }}</td>
                            <td class="py-3">
                                <span class="px-2 py-1 rounded text-sm {{ $fee->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $fee->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="py-3">
                                <form method="POST" action="{{ route('merchant.listing-fees.toggle', $fee->id) }}">
                                    @csrf
                                    <button type="submit" class="text-sm {{ $fee->is_active ? 'text-yellow-600 hover:underline' : 'text-green-600 hover:underline' }}">
                                        {{ $fee->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No listing fees yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
