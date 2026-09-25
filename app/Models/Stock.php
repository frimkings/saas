<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory, BelongsToBranch;

    protected $table = 'stock_movements';

    protected $fillable = [
        'product_id',
        'user_id',
        'reference_no',
        'movement_type',
        'supplier',
        'batch_number',
        'quantity_before',
        'quantity',
        'quantity_after',
        'cost_price',
        'manufacture_date',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity' => 'integer',
        'quantity_after' => 'integer',
        'cost_price' => 'decimal:2',
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
