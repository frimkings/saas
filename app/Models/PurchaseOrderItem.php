<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class PurchaseOrderItem extends Model
{
    use BelongsToBranch;
    protected $fillable = [
        'purchase_order_id', 'product_id', 'description',
        'quantity_ordered', 'quantity_received', 'unit_cost', 'subtotal',
        'batch_number', 'manufacture_date', 'expiry_date',
    ];

    protected $casts = [
        'quantity_ordered'  => 'float',
        'quantity_received' => 'float',
        'unit_cost'         => 'float',
        'subtotal'          => 'float',
        'manufacture_date'  => 'date',
        'expiry_date'       => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getRemainingAttribute(): float
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
