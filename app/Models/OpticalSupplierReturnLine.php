<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalSupplierReturnLine extends Model
{
    use BelongsToBranch;

    protected $fillable = ['optical_supplier_return_id', 'optical_product_id', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'integer', 'unit_cost' => 'decimal:2'];

    public function product()
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }
}
