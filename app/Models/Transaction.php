<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $factory = TransactionFactory::class;
    protected $fillable = [
        'posting_date', 'transcode', 'tempcode', 'customer_email', 'merchant_email',
        'merchantid', 'description', 'amount', 'country', 'currency', 'startdate',
        'enddate', 'fulfill_days', 'payment_gateway', 'payment_date', 'payment_status',
        'trans_status', 'refunddate', 'releasedate', 'stoprefunddate', 'refunded',
        'extended', 'requestextend', 'reqestrefund', 'confirmed_by_merchant',
        'confirmed_date', 'cancelled_date', 'insert_date', 'amountpaid',
        'fufill_notice_date', 'pepperest_fee', 'paystack_fee', 'RAVE_fee',
        'request_extend', 'stop_payment_date', 'reason_for_stopping', 'refund_date',
        'reason_for_stop_refund', 'stop_refund_date', 'arbitration_request_date',
        'order_id', 'request_refund', 'appid', 'gateway_reference', 'gateway_response',
        'transaction_type_id', 'commission_amount', 'net_amount', 'metadata',
    ];

    protected $table = 'transactions';
    public $timestamps = false;

    protected $casts = [
        'metadata' => 'array',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'email', 'customer_email');
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function getNetAmountAttribute($value)
    {
        return $value ?? (float) $this->amount;
    }
}
