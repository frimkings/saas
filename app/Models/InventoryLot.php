<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventoryLot extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'uuid', 'product_id', 'batch_number', 'manufacture_date', 'expiry_date',
        'unit_cost', 'opening_quantity', 'quantity',
    ];
    protected $casts = [
        'manufacture_date' => 'date', 'expiry_date' => 'date', 'unit_cost' => 'decimal:2',
        'opening_quantity' => 'integer', 'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (InventoryLot $lot) => $lot->uuid ??= (string) Str::uuid());
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
