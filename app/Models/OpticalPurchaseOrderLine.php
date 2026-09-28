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

    /**
     * Quantity as it is ordered from the manufacturer: single vision stock lenses in pairs of
     * two, progressive/bifocal lenses per eye (each is half of a right + left pair).
     */
    public function quantityLabel(?int $lenses = null): string
    {
        $lenses ??= $this->quantity_ordered;
        $specs = $this->product?->lens_specs;
        if (! $specs) return (string) $lenses;
        $eye = data_get($specs, 'eye');
        if ($eye) return $lenses.' '.($eye === 'R' ? 'right' : 'left');
        return $lenses % 2 === 0 ? ($lenses / 2).' '.\Illuminate\Support\Str::plural('pair', $lenses / 2) : $lenses.' lenses';
    }
}
