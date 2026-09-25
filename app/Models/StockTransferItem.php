<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    protected $fillable = ['stock_transfer_id', 'product_id', 'inventory_lot_id', 'quantity'];
    protected $casts = ['quantity' => 'integer'];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }
}
