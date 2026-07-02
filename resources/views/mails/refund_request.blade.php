@extends('mails.layout')
@section('title', 'Refund Request')
@section('content')
<p>A refund has been requested for transaction <strong>{{ $transcode }}</strong>.</p>
<ul>
    <li><strong>Requested By:</strong> {{ $requester ?? 'Customer' }}</li>
    <li><strong>Amount:</strong> {{ number_format($amount, 2) }} {{ $currency ?? 'NGN' }}</li>
    <li><strong>Reason:</strong> {{ $reason ?? 'No reason provided' }}</li>
</ul>
<p>Please log in to the dashboard to review and process this request.</p>
<p>Thank you for using Pepper Escrow.</p>
@endsection
