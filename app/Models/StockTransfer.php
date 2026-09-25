<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StockTransfer extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'uuid', 'destination_branch_id', 'transfer_number', 'status', 'created_by',
        'dispatched_by', 'received_by', 'dispatched_at', 'received_at', 'notes',
    ];
    protected $casts = ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (StockTransfer $transfer) => $transfer->uuid ??= (string) Str::uuid());
    }

    public function destinationBranch()
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
