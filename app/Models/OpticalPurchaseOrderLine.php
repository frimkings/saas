<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalPurchaseOrderLine extends Model
{
    use BelongsToBranch;

    protected $fillable = ['optical_purchase_order_id', 'optical_product_id', 'lens_order_id', 'eye', 'description', 'quantity_ordered', 'quantity_received', 'unit_cost'];

    protected $casts = ['quantity_ordered' => 'integer', 'quantity_received' => 'integer', 'unit_cost' => 'decimal:2'];

    public function purchaseOrder()
    {
        return $this->belongsTo(OpticalPurchaseOrder::class, 'optical_purchase_order_id');
    }

    public function product()
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }

    public function lensOrder()
    {
        return $this->belongsTo(LensOrder::class, 'lens_order_id');
    }

    /** A lens bought for a customer job: received straight to the job, not into stock. */
    public function isSpecialOrder(): bool
    {
        return $this->lens_order_id !== null;
    }

    public function outstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
