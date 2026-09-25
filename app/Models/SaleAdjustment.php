<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class SaleAdjustment extends Model
{
    use BelongsToBranch;
    protected $fillable = [
        'sale_id', 'type', 'amount', 'method', 'input_value',
        'approved_by', 'created_by', 'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'input_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sale adjustments are immutable.'));
        static::deleting(fn () => throw new \LogicException('Sale adjustments cannot be deleted.'));
    }

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }
}
