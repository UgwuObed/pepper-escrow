<!DOCTYPE html>
<html><body style="font-family:sans-serif;padding:20px;">
<h2>Payment Received</h2>
<p>A payment of <strong>{{ number_format($amount, 2) }}</strong> has been received for transaction <strong>{{ $transcode }}</strong>.</p>
<p>Customer: {{ $customer }}</p>
<p>Merchant: {{ $merchant->business_name ?? $merchant->email }}</p>
</body></html>
