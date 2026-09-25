<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalLensBlank extends Model
{
    use BelongsToBranch;

    protected $fillable = ['lens_index', 'coating', 'sphere', 'cylinder', 'quantity', 'reorder_level', 'design', 'product_range', 'diameter', 'addition', 'selling_price'];
    protected $casts = ['sphere' => 'decimal:2', 'cylinder' => 'decimal:2', 'addition' => 'decimal:2', 'selling_price' => 'decimal:2', 'diameter' => 'integer', 'quantity' => 'integer', 'reorder_level' => 'integer'];
}
