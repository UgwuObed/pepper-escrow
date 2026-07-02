@extends('mails.layout')
@section('title', 'Dispute Opened')
@section('content')
<p>A dispute has been opened for transaction <strong>{{ $transcode }}</strong>.</p>
<ul>
    <li><strong>Category:</strong> {{ $category ?? 'N/A' }}</li>
    <li><strong>Description:</strong> {{ $description ?? 'N/A' }}</li>
    <li><strong>Filed By:</strong> {{ $filedBy ?? 'Customer' }}</li>
</ul>
<p>Please log in to the dashboard to review the dispute details.</p>
<p>Thank you for using Pepper Escrow.</p>
@endsection
