@extends('admin.layout')
@section('title', 'Merchants')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Merchants</h1>
        <form method="GET" class="flex gap-2">
            <input type="text" name="search" placeholder="Search name, email, phone..." value="{{ request('search') }}" class="px-3 py-2 border rounded text-sm">
            <select name="status" class="px-3 py-2 border rounded text-sm">
                <option value="">All Status</option>
                <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>Active</option>
                <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>Suspended</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
            @if(request()->hasAny(['search', 'status']))
            <a href="{{ route('admin.merchants') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr class="text-left text-sm text-gray-600">
                    <th class="px-6 py-3">ID</th>
                    <th class="px-6 py-3">Business Name</th>
                    <th class="px-6 py-3">Email</th>
                    <th class="px-6 py-3">Phone</th>
                    <th class="px-6 py-3">Transactions</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Registered</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($merchants as $merchant)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 text-sm">{{ $merchant->id }}</td>
                    <td class="px-6 py-3 text-sm font-medium">{{ $merchant->business_name }}</td>
                    <td class="px-6 py-3 text-sm">{{ $merchant->email }}</td>
                    <td class="px-6 py-3 text-sm">{{ $merchant->phone ?? '-' }}</td>
                    <td class="px-6 py-3 text-sm">{{ $merchant->transaction_count ?? 0 }}</td>
                    <td class="px-6 py-3">
                        <span class="px-2 py-1 text-xs rounded {{ $merchant->status ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $merchant->status ? 'Active' : 'Suspended' }}</span>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-500">{{ $merchant->created_at->format('Y-m-d') }}</td>
                    <td class="px-6 py-3 text-sm">
                        <a href="{{ route('admin.merchant.detail', $merchant->id) }}" class="text-blue-600 hover:underline">View</a>
                        <form method="POST" action="{{ route('admin.merchant.toggle', $merchant->id) }}" class="inline ml-2" onsubmit="return confirm('{{ $merchant->status ? 'Suspend' : 'Activate' }} this merchant?')">
                            @csrf
                            <button type="submit" class="text-{{ $merchant->status ? 'red' : 'green' }}-600 hover:underline">{{ $merchant->status ? 'Suspend' : 'Activate' }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No merchants found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        {{ $merchants->links() }}
    </div>
</div>
@endsection
