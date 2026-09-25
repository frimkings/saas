<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalSupplierReturn extends Model
{
    use BelongsToBranch;

    public const REASONS = [
        'damaged' => 'Damaged on arrival', 'wrong_item' => 'Wrong item or power', 'defective' => 'Manufacturing defect',
        'excess' => 'Over-supplied', 'expired' => 'Expired or discontinued', 'other' => 'Other',
    ];

    public const SETTLEMENTS = ['pending' => 'Awaiting credit', 'credited' => 'Credit received', 'replaced' => 'Replaced by supplier', 'written_off' => 'Written off'];

    protected $fillable = ['return_number', 'supplier_id', 'optical_purchase_order_id', 'reason', 'notes', 'credit_expected', 'credit_status', 'credit_reference', 'settled_at', 'created_by'];

    protected $casts = ['credit_expected' => 'decimal:2', 'settled_at' => 'datetime'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(OpticalPurchaseOrder::class, 'optical_purchase_order_id');
    }

    public function lines()
    {
        return $this->hasMany(OpticalSupplierReturnLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
