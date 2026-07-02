@extends('admin.layout')
@section('title', 'Notification Logs')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Notification Logs</h1>
        <form method="GET" class="flex gap-2 flex-wrap">
            <select name="merchant_id" class="px-3 py-2 border rounded text-sm">
                <option value="">All Merchants</option>
@foreach ($merchants as $m)
                <option value="{{ $m->id }}" {{ request('merchant_id') == $m->id ? 'selected' : '' }}>{{ $m->business_name }}</option>
@endforeach
            </select>
            <select name="event" class="px-3 py-2 border rounded text-sm">
                <option value="">All Events</option>
@foreach ($events as $ev)
                <option value="{{ $ev }}" {{ request('event') === $ev ? 'selected' : '' }}>{{ $ev }}</option>
@endforeach
            </select>
            <select name="status" class="px-3 py-2 border rounded text-sm">
                <option value="">All Status</option>
                <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
@if(request()->hasAny(['merchant_id', 'event', 'status']))
            <a href="{{ route('admin.notifications') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
@endif
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-3">ID</th>
                        <th class="px-6 py-3">Merchant</th>
                        <th class="px-6 py-3">Channel</th>
                        <th class="px-6 py-3">Event</th>
                        <th class="px-6 py-3">Recipient</th>
                        <th class="px-6 py-3">Subject</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Response</th>
                        <th class="px-6 py-3">Sent At</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
@forelse ($logs as $log)
                    <tr class="hover:bg-gray-50 text-sm">
                        <td class="px-6 py-3">{{ $log->id }}</td>
                        <td class="px-6 py-3">{{ $log->merchant->business_name ?? 'N/A' }}</td>
                        <td class="px-6 py-3">{{ $log->channel }}</td>
                        <td class="px-6 py-3 font-mono text-xs">{{ $log->event }}</td>
                        <td class="px-6 py-3">{{ $log->recipient }}</td>
                        <td class="px-6 py-3 max-w-xs truncate">{{ $log->subject }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 text-xs rounded {{ $log->status === 'sent' ? 'bg-green-100 text-green-700' : ($log->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">{{ ucfirst($log->status) }}</span>
                        </td>
                        <td class="px-6 py-3 text-xs">{{ $log->response_code ?? '-' }}</td>
                        <td class="px-6 py-3 text-gray-500">{{ $log->sent_at ? \Carbon\Carbon::parse($log->sent_at)->format('Y-m-d H:i') : '-' }}</td>
                    </tr>
@empty
                    <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500">No notification logs found.</td></tr>
@endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</div>
@endsection
