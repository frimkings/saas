<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class PurchaseOrder extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'po_number', 'supplier_id', 'status', 'order_date', 'expected_date',
        'notes', 'total_amount', 'created_by', 'received_by', 'received_at',
        'invoice_number', 'invoice_date', 'invoice_due_date', 'invoice_amount',
        'paid_amount', 'payment_method', 'payment_reference', 'paid_at', 'invoice_status',
    ];

    protected $casts = [
        'order_date'       => 'date',
        'expected_date'    => 'date',
        'received_at'      => 'datetime',
        'total_amount'     => 'float',
        'invoice_date'     => 'date',
        'invoice_due_date' => 'date',
        'invoice_amount'   => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'paid_at'          => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function nextNumber(): string
    {
        $year  = now()->year;
        $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
        $prefix = app(\App\Support\Tenancy\TenantContext::class)->branch()?->receipt_prefix
            ?: app(\App\Support\Tenancy\TenantContext::class)->branch()?->code
            ?: 'MAIN';
        return 'PO-' . strtoupper($prefix) . '-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->items->every(
            fn ($i) => $i->quantity_received >= $i->quantity_ordered
        );
    }

    public function getInvoiceBalanceDueAttribute(): float
    {
        $base = (float) ($this->invoice_amount ?? $this->total_amount ?? 0);
        return max(0, $base - (float) ($this->paid_amount ?? 0));
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->invoice_due_date
            && $this->invoice_status !== 'paid'
            && $this->invoice_due_date->isPast();
    }

    public function invoiceStatusBadgeClass(): string
    {
        return match($this->invoice_status) {
            'invoiced' => 'badge-primary',
            'partial'  => 'badge-warning',
            'paid'     => 'badge-success',
            default    => 'badge-secondary',
        };
    }
}
