<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpticalProductStockMovement extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'optical_product_id', 'user_id', 'quantity_change', 'balance_after', 'reason',
        'movement_type', 'reference', 'supplier', 'batch_number', 'unit_cost',
        'unit_price', 'notes', 'reverses_movement_id', 'optical_lens_import_id',
        'optical_purchase_order_line_id', 'optical_supplier_return_id', 'optical_stock_count_id',
    ];

    protected $casts = [
        'quantity_change' => 'integer', 'balance_after' => 'integer',
        'unit_cost' => 'decimal:2', 'unit_price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }
}
