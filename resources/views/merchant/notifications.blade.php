@extends('merchant.layout')
@section('title', 'Notifications')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Notification Log</h1>
        <div class="flex gap-3">
            <a href="{{ route('merchant.settings') }}" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Webhook Settings</a>
            <a href="{{ route('merchant.dashboard') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('merchant.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Delivery Log</h2>
            <span class="text-sm text-gray-500">{{ $logs->total() }} total</span>
        </div>

        @if ($logs->total() > 0)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            @php
                $sent = $logs->getCollection()->where('status', 'sent')->count();
                $failed = $logs->getCollection()->where('status', 'failed')->count();
                $pending = $logs->getCollection()->where('status', 'pending')->count();
            @endphp
            <div class="bg-green-50 p-3 rounded border border-green-200 text-center">
                <span class="text-2xl font-bold text-green-700">{{ $sent }}</span>
                <p class="text-sm text-green-600">Sent</p>
            </div>
            <div class="bg-red-50 p-3 rounded border border-red-200 text-center">
                <span class="text-2xl font-bold text-red-700">{{ $failed }}</span>
                <p class="text-sm text-red-600">Failed</p>
            </div>
            <div class="bg-yellow-50 p-3 rounded border border-yellow-200 text-center">
                <span class="text-2xl font-bold text-yellow-700">{{ $pending }}</span>
                <p class="text-sm text-yellow-600">Pending</p>
            </div>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="text-left text-gray-600 border-b">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Channel</th>
                        <th class="pb-2">Event</th>
                        <th class="pb-2">Recipient</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Code</th>
                        <th class="pb-2">Attempts</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 text-sm">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $log->channel === 'webhook' ? 'bg-purple-100 text-purple-700' : '' }}
                                {{ $log->channel === 'email' ? 'bg-blue-100 text-blue-700' : '' }}
                            ">{{ ucfirst($log->channel) }}</span>
                        </td>
                        <td class="py-3 font-mono text-sm">{{ $log->event }}</td>
                        <td class="py-3 text-sm" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $log->recipient }}</td>
                        <td class="py-3">
                            <span class="px-2 py-1 rounded text-sm
                                {{ $log->status === 'sent' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $log->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $log->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                            ">{{ ucfirst($log->status) }}</span>
                        </td>
                        <td class="py-3 font-mono text-sm">{{ $log->response_code ?? '—' }}</td>
                        <td class="py-3">{{ $log->attempts }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-500">No notifications yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
        <div class="mt-4">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
@endsection
