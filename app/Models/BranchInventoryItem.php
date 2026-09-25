<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class BranchInventoryItem extends Model
{
    use BelongsToBranch;

    protected $fillable = ['product_id', 'quantity', 'reorder_level', 'is_active', 'version'];
    protected $casts = ['quantity' => 'integer', 'reorder_level' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
