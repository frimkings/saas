<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpticalProductStock extends Model
{
    use BelongsToBranch;

    protected $fillable = ['optical_product_id', 'quantity', 'reorder_level', 'lens_reorder_pairs', 'lens_target_pairs'];

    protected $casts = ['quantity' => 'integer', 'reorder_level' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(OpticalProduct::class, 'optical_product_id');
    }
}
