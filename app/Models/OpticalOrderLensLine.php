<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\RecordsUnitCost;
use Illuminate\Database\Eloquent\Model;

class OpticalOrderLensLine extends Model
{
    use BelongsToBranch, RecordsUnitCost;

    protected function unitCostSources(): array
    {
        return ['unit_cost' => ['optical_product_id' => OpticalProduct::class]];
    }

    // Stock lines: quoted -> held -> consumed (glazing) | released (cancel).
    // Special-order lines: quoted -> ordered -> received.
    public const HOLDING = ['held'];

    protected $fillable = [
        'lens_order_id', 'eye', 'source', 'optical_product_id', 'sphere', 'power',
        'unit_price', 'unit_cost', 'price_estimated', 'status', 'consumed_at', 'received_at',
    ];

    protected $casts = [
        'sphere' => 'decimal:2',
        'power' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'price_estimated' => 'boolean',
        'consumed_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(LensOrder::class, 'lens_order_id');
    }

    public function product()
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }
}
