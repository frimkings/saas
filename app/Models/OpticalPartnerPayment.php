<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalPartnerPayment extends Model
{
    use BelongsToBranch;

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'momo' => 'Mobile Money', 'cash' => 'Cash', 'card' => 'Card'];

    protected $fillable = ['optical_partner_clinic_id', 'receipt_number', 'amount', 'payment_method', 'reference', 'notes', 'received_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function partner()
    {
        return $this->belongsTo(OpticalPartnerClinic::class, 'optical_partner_clinic_id');
    }

    public function allocations()
    {
        return $this->hasMany(OpticalPartnerPaymentAllocation::class)->orderBy('id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
