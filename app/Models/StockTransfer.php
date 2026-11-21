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
        'response_note', 'answered_by', 'answered_at',
    ];
    protected $casts = ['dispatched_at' => 'datetime', 'received_at' => 'datetime', 'answered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (StockTransfer $transfer) => $transfer->uuid ??= (string) Str::uuid());
    }

    /** The branch the stock comes from (for a request: the branch asked). */
    public function sourceBranch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
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
