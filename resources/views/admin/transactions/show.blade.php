@extends('admin.layout')
@section('title', 'Transaction #' . $transaction->id)
@section('content')
<div class="p-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('admin.transactions') }}" class="text-blue-600 hover:underline">&larr; Back</a>
        <h1 class="text-2xl font-bold">Transaction {{ $transaction->transcode }}</h1>
        <span class="px-2 py-1 text-xs rounded {{ $transaction->trans_status === 'Released' ? 'bg-green-100 text-green-700' : ($transaction->trans_status === 'Fulfilled' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">{{ $transaction->trans_status }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-bold mb-4">Transaction Details</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-gray-500">ID</dt><dd>{{ $transaction->id }}</dd></div>
                <div><dt class="text-gray-500">Code</dt><dd class="font-mono">{{ $transaction->transcode }}</dd></div>
                <div><dt class="text-gray-500">Merchant ID</dt><dd>{{ $transaction->appid }}</dd></div>
                <div><dt class="text-gray-500">Merchant Email</dt><dd>{{ $transaction->merchant_email ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Customer Email</dt><dd>{{ $transaction->customer_email }}</dd></div>
                <div><dt class="text-gray-500">Description</dt><dd>{{ $transaction->description ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Amount</dt><dd class="font-bold">{{ number_format($transaction->amount, 2) }}</dd></div>
                <div><dt class="text-gray-500">Commission</dt><dd>{{ number_format($transaction->commission_amount ?? $transaction->pepperest_fee ?? 0, 2) }}</dd></div>
                <div><dt class="text-gray-500">Net Amount</dt><dd>{{ number_format($transaction->net_amount ?? $transaction->amount, 2) }}</dd></div>
                <div><dt class="text-gray-500">Currency</dt><dd>{{ $transaction->currency ?? 'NGN' }}</dd></div>
                <div><dt class="text-gray-500">Country</dt><dd>{{ $transaction->country ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Payment Gateway</dt><dd>{{ $transaction->payment_gateway ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Gateway Reference</dt><dd class="font-mono text-xs">{{ $transaction->gateway_reference ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Posting Date</dt><dd>{{ $transaction->posting_date ? \Carbon\Carbon::parse($transaction->posting_date)->format('Y-m-d H:i') : '-' }}</dd></div>
                <div><dt class="text-gray-500">Payment Date</dt><dd>{{ $transaction->payment_date ? \Carbon\Carbon::parse($transaction->payment_date)->format('Y-m-d H:i') : '-' }}</dd></div>
                <div><dt class="text-gray-500">Release Date</dt><dd>{{ $transaction->releasedate ? \Carbon\Carbon::parse($transaction->releasedate)->format('Y-m-d H:i') : '-' }}</dd></div>
            </dl>
        </div>

        <div class="space-y-6">
            @if ($merchant)
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Merchant</h2>
                <p class="text-sm"><a href="{{ route('admin.merchant.detail', $merchant->id) }}" class="text-blue-600 hover:underline">{{ $merchant->business_name }}</a></p>
                <p class="text-sm text-gray-500">{{ $merchant->email }}</p>
            </div>
            @endif

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Status Timeline</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Payment Status</dt><dd>{{ $transaction->payment_status ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Transaction Status</dt><dd>{{ $transaction->trans_status }}</dd></div>
                    <div><dt class="text-gray-500">Fulfill Days</dt><dd>{{ $transaction->fulfill_days ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Refunded</dt><dd>{{ $transaction->refunded ? 'Yes' : 'No' }}</dd></div>
                    <div><dt class="text-gray-500">Extended</dt><dd>{{ $transaction->extended ? 'Yes' : 'No' }}</dd></div>
                    <div><dt class="text-gray-500">Confirmed by Merchant</dt><dd>{{ $transaction->confirmed_by_merchant ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </div>

            @if ($transaction->metadata)
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold mb-4">Metadata</h2>
                <pre class="text-xs bg-gray-50 p-3 rounded overflow-x-auto">{{ json_encode($transaction->metadata, JSON_PRETTY_PRINT) }}</pre>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
