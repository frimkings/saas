<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalPurchaseOrder extends Model
{
    use BelongsToBranch;

    public const STATUSES = [
        'draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Part received',
        'received' => 'Received', 'cancelled' => 'Cancelled',
    ];

    protected $fillable = ['po_number', 'supplier_id', 'status', 'expected_date', 'notes', 'created_by', 'ordered_at', 'received_at', 'cancelled_at'];

    protected $casts = ['expected_date' => 'date', 'ordered_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(OpticalPurchaseOrderLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalCost(): float
    {
        return round((float) $this->lines->sum(fn ($line) => $line->quantity_ordered * (float) $line->unit_cost), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['ordered', 'partially_received'], true);
    }
}
