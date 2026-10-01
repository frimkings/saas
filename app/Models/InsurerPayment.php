<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** One payment received from an insurer, split across the claims it settles. */
class InsurerPayment extends Model
{
    use BelongsToBranch;

    public const PERMISSION = 'record insurer payments';

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'momo' => 'Mobile Money', 'cash' => 'Cash'];

    protected $fillable = ['insurer_id', 'receipt_number', 'amount', 'payment_method', 'reference', 'paid_on', 'notes', 'received_by'];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function insurer()
    {
        return $this->belongsTo(Insurer::class)->withTrashed();
    }

    public function allocations()
    {
        return $this->hasMany(InsurerPaymentAllocation::class)->orderBy('id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
