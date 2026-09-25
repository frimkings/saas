<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpticalProduct extends Model
{
    use BelongsToClinic, SoftDeletes;

    protected $fillable = [
        'optical_category_id', 'sku', 'name', 'brand', 'specifications',
        'cost_price', 'selling_price', 'is_active', 'lens_specs', 'lens_key',
    ];

    protected $casts = [
        'lens_specs' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OpticalCategory::class, 'optical_category_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(OpticalProductStock::class);
    }
}
