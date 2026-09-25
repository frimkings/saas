<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalPartnerPaymentAllocation extends Model
{
    use BelongsToBranch;

    protected $fillable = ['optical_partner_payment_id', 'lens_order_id', 'payment_transaction_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(OpticalPartnerPayment::class, 'optical_partner_payment_id');
    }

    public function order()
    {
        return $this->belongsTo(LensOrder::class, 'lens_order_id');
    }
}
