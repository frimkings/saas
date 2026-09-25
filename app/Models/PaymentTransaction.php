<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use BelongsToBranch;
    protected $fillable = [
        'sale_id',
        'idempotency_key',
        'amount',
        'payment_method',
        'notes',
        'collected_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
