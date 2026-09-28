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

    /**
     * Lines as the supplier should read them: lens powers in pairs, with a progressive or
     * bifocal right + left of the same power shown as one pair row.
     *
     * @return \Illuminate\Support\Collection<int, array{description: string, quantity: string, unit_cost: float, total: float}>
     */
    public function supplierLines()
    {
        $planner = app(\App\Services\OpticalLensOrderPlanner::class);
        $rows = collect();
        $eyeLines = $this->lines->filter(fn ($line) => data_get($line->product?->lens_specs, 'eye') && ! $line->isSpecialOrder());
        $paired = [];
        foreach ($eyeLines->groupBy(fn ($line) => json_encode(collect($line->product->lens_specs)->except('eye')->sortKeys()->all())) as $group) {
            $right = $group->first(fn ($l) => data_get($l->product->lens_specs, 'eye') === 'R');
            $left = $group->first(fn ($l) => data_get($l->product->lens_specs, 'eye') === 'L');
            if (! $right || ! $left || $right->quantity_ordered !== $left->quantity_ordered) continue;
            $specs = $right->product->lens_specs;
            $pairCost = (float) $right->unit_cost + (float) $left->unit_cost;
            $rows[$right->id] = ['description' => $planner->label($specs, (float) $specs['sphere'], (float) $specs['power']),
                'quantity' => $right->quantity_ordered.' '.\Illuminate\Support\Str::plural('pair', $right->quantity_ordered),
                'unit_cost' => $pairCost, 'total' => round($right->quantity_ordered * $pairCost, 2)];
            $paired[] = $left->id;
        }
        foreach ($this->lines as $line) {
            if (isset($rows[$line->id]) || in_array($line->id, $paired, true)) continue;
            $isStockLens = $line->product?->lens_specs && ! data_get($line->product->lens_specs, 'eye');
            $inPairs = $isStockLens && $line->quantity_ordered % 2 === 0;
            $rows[$line->id] = ['description' => $line->description, 'quantity' => $line->quantityLabel(),
                'unit_cost' => $inPairs ? 2 * (float) $line->unit_cost : (float) $line->unit_cost,
                'total' => round($line->quantity_ordered * (float) $line->unit_cost, 2)];
        }
        return $rows->sortKeys()->values();
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
