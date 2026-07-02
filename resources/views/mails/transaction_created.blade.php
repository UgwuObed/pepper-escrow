@extends('mails.layout')
@section('title', 'Transaction Created')
@section('content')
<p>Dear {{ $customerName ?? 'Customer' }},</p>
<p>A new escrow transaction has been created with the following details:</p>
<ul>
    <li><strong>Transaction Code:</strong> {{ $transcode }}</li>
    <li><strong>Amount:</strong> {{ number_format($amount, 2) }} {{ $currency ?? 'NGN' }}</li>
    <li><strong>Description:</strong> {{ $description ?? 'N/A' }}</li>
    <li><strong>Status:</strong> {{ $status ?? 'Pending' }}</li>
</ul>
@if ($paymentUrl ?? false)
<p><a href="{{ $paymentUrl }}" style="display:inline-block;padding:12px 24px;background:#1a56db;color:#fff;text-decoration:none;border-radius:4px;">Make Payment</a></p>
@endif
<p>Thank you for using Pepper Escrow.</p>
@endsection
