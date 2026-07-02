@extends('mails.layout')
@section('title', 'Transaction Released')
@section('content')
<p>Dear {{ $customerName ?? 'Customer' }},</p>
<p>Your transaction <strong>{{ $transcode }}</strong> has been released to the merchant.</p>
<ul>
    <li><strong>Amount Released:</strong> {{ number_format($amount, 2) }} {{ $currency ?? 'NGN' }}</li>
    <li><strong>Release Date:</strong> {{ $releaseDate ?? now()->format('Y-m-d H:i:s') }}</li>
</ul>
<p>Thank you for using Pepper Escrow.</p>
@endsection
