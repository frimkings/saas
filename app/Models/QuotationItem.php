<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class QuotationItem extends Model
{
    use BelongsToBranch;
    protected $fillable = [
        'quotation_id', 'product_id', 'description',
        'quantity', 'unit_price', 'subtotal',
    ];

    protected $casts = [
        'quantity'   => 'float',
        'unit_price' => 'float',
        'subtotal'   => 'float',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
