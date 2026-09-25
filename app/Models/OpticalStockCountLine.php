<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalStockCountLine extends Model
{
    use BelongsToBranch;

    protected $fillable = ['optical_stock_count_id', 'optical_product_id', 'expected_quantity', 'counted_quantity', 'moved_before_count', 'unit_cost'];

    protected $casts = ['expected_quantity' => 'integer', 'counted_quantity' => 'integer', 'moved_before_count' => 'integer', 'unit_cost' => 'decimal:2'];

    public function product()
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }

    /** What the system expected when this item was counted: the start quantity plus stock moved since. */
    public function expectedAtCount(): int
    {
        return $this->expected_quantity + (int) $this->moved_before_count;
    }

    /** Counted minus expected at the time of counting; null until counted. */
    public function variance(): ?int
    {
        return $this->counted_quantity === null ? null : $this->counted_quantity - $this->expectedAtCount();
    }
}
