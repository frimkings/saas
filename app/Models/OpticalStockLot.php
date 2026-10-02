<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * A batch of optical stock with an expiry date (contact lenses, solutions), recorded when the
 * stock is received. Feeds the expiry reminders in App\Services\Reminders\AttentionItems.
 */
class OpticalStockLot extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'optical_product_id', 'optical_product_stock_movement_id', 'batch_number', 'expiry_date', 'opening_quantity', 'quantity',
    ];

    protected $casts = ['expiry_date' => 'date', 'opening_quantity' => 'integer', 'quantity' => 'integer'];

    public function product()
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id')->withTrashed();
    }
}
