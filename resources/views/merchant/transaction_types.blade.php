@extends('merchant.layout')
@section('title', 'Transaction Types')
@section('content')
<h1 class="text-2xl font-bold mb-6">Transaction Types</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Create Form --}}
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">New Transaction Type</h2>
            <form method="POST" action="{{ route('merchant.transaction-types.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full border rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"></textarea>
                </div>
                <div class="mb-4 flex items-center gap-6">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="supports_escrow" value="1" checked>
                        <span class="text-sm">Supports Escrow</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="requires_fulfillment" value="1" checked>
                        <span class="text-sm">Requires Fulfillment</span>
                    </label>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">Create</button>
            </form>
            <form method="POST" action="{{ route('merchant.transaction-types.seed') }}" class="mt-4">
                @csrf
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 w-full">Seed Defaults</button>
            </form>
        </div>

        {{-- Types List --}}
        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">All Types</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="text-left text-gray-600 border-b">
                            <th class="pb-2">Name</th>
                            <th class="pb-2">Slug</th>
                            <th class="pb-2">Escrow</th>
                            <th class="pb-2">Fulfillment</th>
                            <th class="pb-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($types as $type)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 font-medium">{{ $type->name }}</td>
                            <td class="py-3 font-mono text-sm">{{ $type->slug }}</td>
                            <td class="py-3">{{ $type->supports_escrow ? 'Yes' : 'No' }}</td>
                            <td class="py-3">{{ $type->requires_fulfillment ? 'Yes' : 'No' }}</td>
                            <td class="py-3">
                                <span class="px-2 py-1 rounded text-sm {{ $type->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $type->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No transaction types yet. Create one or seed defaults.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
