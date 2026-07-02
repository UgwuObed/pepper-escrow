@extends('mails.layout')
@section('title', 'Transaction Stopped')
@section('content')
<p>Dear {{ $customerName ?? 'Customer' }},</p>
<p>Transaction <strong>{{ $transcode }}</strong> has been stopped.</p>
<ul>
    <li><strong>Reason:</strong> {{ $reason ?? 'No reason provided' }}</li>
    <li><strong>Stop Date:</strong> {{ $stopDate ?? now()->format('Y-m-d H:i:s') }}</li>
</ul>
<p>Please contact support if you have any questions.</p>
<p>Thank you for using Pepper Escrow.</p>
@endsection
