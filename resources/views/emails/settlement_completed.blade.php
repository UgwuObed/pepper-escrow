<!DOCTYPE html>
<html><body style="font-family:sans-serif;padding:20px;">
<h2>Settlement Completed</h2>
<p>Batch <strong>{{ $batch }}</strong> has been settled successfully.</p>
<p>Net amount: <strong>{{ number_format($amount, 2) }}</strong></p>
<p>Transactions: {{ $count }}</p>
</body></html>
