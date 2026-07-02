<?php

namespace App\Models;

use Database\Factories\CommissionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    use HasFactory;

    protected $factory = CommissionRuleFactory::class;
    protected $fillable = [
        'merchant_id',
        'transaction_type_id',
        'name',
        'rate_type',
        'rate_value',
        'cap_amount',
        'min_amount',
        'max_amount',
        'priority',
        'payer',
        'status',
    ];

    protected $casts = [
        'rate_value' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'priority' => 'integer',
        'status' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    public function appliesTo(float $amount): bool
    {
        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }
        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            return false;
        }
        return true;
    }

    public function calculateCommission(float $amount): float
    {
        $commission = ($this->rate_type === 'percentage')
            ? ($amount * (float) $this->rate_value / 100)
            : (float) $this->rate_value;

        if ($this->cap_amount !== null && $commission > (float) $this->cap_amount) {
            $commission = (float) $this->cap_amount;
        }

        return round($commission, 2);
    }
}
