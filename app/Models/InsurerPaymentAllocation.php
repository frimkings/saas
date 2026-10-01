<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class InsurerPaymentAllocation extends Model
{
    use BelongsToBranch;

    protected $fillable = ['insurer_payment_id', 'insurance_claim_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(InsurerPayment::class, 'insurer_payment_id');
    }

    public function claim()
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id')->withTrashed();
    }
}
