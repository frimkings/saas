<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\BranchInventoryItem;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\OpticalProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BranchInventoryService
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function quantity(Product|OpticalProduct|int $product, ?int $branchId = null): int
    {
        if ($product instanceof OpticalProduct) {
            return (int) ($product->stocks->first()?->quantity ?? 0);
        }

        $productId = $product instanceof Product ? $product->getKey() : $product;
        $branch = $this->branch($branchId);
        $resolvedProduct = Product::withoutGlobalScopes()->findOrFail($productId);
        if ((int) $resolvedProduct->clinic_id !== (int) $branch->clinic_id) {
            throw new RuntimeException('The product and branch belong to different clinics.');
        }
        $branchId = $branch->id;

        $quantity = BranchInventoryItem::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('quantity');

        if ($quantity !== null) {
            return (int) $quantity;
        }

        // A legacy aggregate belongs to the first/default branch until the
        // first branch inventory row is materialized. Thereafter, a missing
        // branch row correctly represents zero stock at that branch.
        if (! BranchInventoryItem::withoutGlobalScopes()->where('product_id', $productId)->exists()) {
            return (int) $resolvedProduct->quantity;
        }

        return 0;
    }

    /** Returns null for a made-to-order product: its stock is never counted. */
    public function increase(Product|int $product, int $quantity, array $lot = [], ?int $branchId = null): ?BranchInventoryItem
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Inventory quantity must be greater than zero.');
        }

        return $this->adjust($product, $quantity, $branchId, $lot);
    }

    /** The quantity at which this branch should reorder the product (shown as low stock from there). */
    public function setReorderLevel(Product $product, int $level, ?int $branchId = null): void
    {
        if ($product->made_to_order) return;
        $branch = $this->branch($branchId);
        DB::transaction(function () use ($product, $level, $branch) {
            // A first row takes over the product's stock, as the first stock movement would.
            $hasInventory = BranchInventoryItem::withoutGlobalScopes()->where('product_id', $product->id)->exists();
            $row = BranchInventoryItem::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'product_id' => $product->id],
                ['clinic_id' => $branch->clinic_id, 'quantity' => $hasInventory ? 0 : (int) $product->quantity, 'reorder_level' => $level]
            );
            if ((int) $row->reorder_level !== $level) $row->forceFill(['reorder_level' => $level])->save();
        });
    }

    /** Returns null for a made-to-order product: its stock is never counted. */
    public function decrease(Product|int $product, int $quantity, ?int $branchId = null, ?int $lotId = null): ?BranchInventoryItem
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Inventory quantity must be greater than zero.');
        }

        return $this->adjust($product, -$quantity, $branchId, [], $lotId);
    }

    private function adjust(Product|int $product, int $delta, ?int $branchId, array $lot = [], ?int $lotId = null): ?BranchInventoryItem
    {
        $productId = $product instanceof Product ? (int) $product->getKey() : $product;
        $branch = $this->branch($branchId);

        return DB::transaction(function () use ($productId, $branch, $delta, $lot, $lotId) {
            $product = Product::withoutGlobalScopes()->lockForUpdate()->findOrFail($productId);
            if ((int) $product->clinic_id !== (int) $branch->clinic_id) {
                throw new RuntimeException('The product and branch belong to different clinics.');
            }
            if ($product->made_to_order) return null;

            $hasInventory = BranchInventoryItem::withoutGlobalScopes()
                ->where('product_id', $productId)->exists();
            $inventory = BranchInventoryItem::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'product_id' => $productId],
                [
                    'clinic_id' => $branch->clinic_id,
                    'quantity' => $hasInventory ? 0 : (int) $product->quantity,
                    'reorder_level' => 10,
                ]
            );
            $inventory = BranchInventoryItem::withoutGlobalScopes()->lockForUpdate()->findOrFail($inventory->id);
            $before = (int) $inventory->quantity;
            $next = $before + $delta;
            if ($next < 0) {
                throw new RuntimeException('Insufficient stock for '.$product->name.' at '.$branch->name.'.');
            }

            $inventory->forceFill(['quantity' => $next, 'version' => (int) $inventory->version + 1])->save();

            // The legacy aggregate remains synchronized during the staged rollout.
            $product->forceFill([
                'quantity' => (int) BranchInventoryItem::withoutGlobalScopes()
                    ->where('product_id', $productId)->sum('quantity'),
            ])->save();

            if ($delta < 0) {
                $this->drawDownLots($product, $branch, -$delta, $before, $lotId);
            }

            if ($delta > 0 && ($lot['batch_number'] ?? $lot['expiry_date'] ?? null)) {
                $this->openingLot($product, $branch, $before);
                InventoryLot::withoutGlobalScopes()->create([
                    'clinic_id' => $branch->clinic_id,
                    'branch_id' => $branch->id,
                    'product_id' => $productId,
                    'batch_number' => $lot['batch_number'] ?? null,
                    'manufacture_date' => $lot['manufacture_date'] ?? null,
                    'expiry_date' => $lot['expiry_date'] ?? null,
                    'unit_cost' => $lot['unit_cost'] ?? null,
                    'opening_quantity' => $delta,
                    'quantity' => $delta,
                ]);
            }
            $this->syncProductExpiry($product);

            return $inventory->fresh();
        });
    }

    /**
     * Stock held before batches were tracked becomes a batch of its own (the product's batch and
     * expiry) the first time a dated delivery arrives at the branch, so every unit on the shelf
     * belongs to a batch from then on.
     */
    private function openingLot(Product $product, Branch $branch, int $onHand): void
    {
        if ($onHand <= 0) return;
        $lots = InventoryLot::withoutGlobalScopes()->where('branch_id', $branch->id)->where('product_id', $product->id);
        $unbatched = $onHand - (int) (clone $lots)->sum('quantity');
        if ($unbatched <= 0 || (clone $lots)->exists()) return;

        InventoryLot::withoutGlobalScopes()->create([
            'clinic_id' => $branch->clinic_id, 'branch_id' => $branch->id, 'product_id' => $product->id,
            'batch_number' => $product->batch_number, 'manufacture_date' => $product->manufacture_date,
            'expiry_date' => $product->expiry_date, 'unit_cost' => $product->cost_price,
            'opening_quantity' => $unbatched, 'quantity' => $unbatched,
        ]);
    }

    /**
     * Stock leaving the branch comes out of its batches: the named one (an expiry write-off), else
     * the in-date batch that expires first. Expired batches are used last, so they stay flagged
     * until someone takes them off the shelf.
     */
    private function drawDownLots(Product $product, Branch $branch, int $quantity, int $onHandBefore, ?int $lotId): void
    {
        $lots = InventoryLot::withoutGlobalScopes()->where('branch_id', $branch->id)->where('product_id', $product->id)
            ->where('quantity', '>', 0)->lockForUpdate()->get();
        if ($lots->isEmpty()) return;

        if ($lotId !== null && ($named = $lots->firstWhere('id', $lotId))) {
            $take = min($quantity, (int) $named->quantity);
            $named->forceFill(['quantity' => (int) $named->quantity - $take])->save();
            $quantity -= $take;
        }

        $today = now()->startOfDay();
        $inDate = $lots->filter(fn ($lot) => !$lot->expiry_date || $lot->expiry_date->gte($today))
            ->sortBy(fn ($lot) => $lot->expiry_date?->timestamp ?? PHP_INT_MAX);
        $expired = $lots->filter(fn ($lot) => $lot->expiry_date && $lot->expiry_date->lt($today))->sortBy(fn ($lot) => $lot->expiry_date->timestamp);
        // Units held outside any batch (from before batches were tracked) go after the in-date batches.
        $unbatched = max(0, $onHandBefore - (int) $lots->sum('quantity'));

        foreach ([$inDate, null, $expired] as $group) {
            if ($quantity <= 0) break;
            if ($group === null) { $quantity -= min($quantity, $unbatched); continue; }
            foreach ($group as $lot) {
                if ($quantity <= 0) break;
                $lot->refresh();
                $take = min($quantity, (int) $lot->quantity);
                if ($take <= 0) continue;
                $lot->forceFill(['quantity' => (int) $lot->quantity - $take])->save();
                $quantity -= $take;
            }
        }
    }

    /**
     * The product shows the expiry of its next in-date batch that still has stock (the till hides
     * expired products), or, with only expired stock left, the earliest expired batch. Expired
     * batches are flagged by Inventory Alerts and Needs Attention from the batches themselves.
     */
    private function syncProductExpiry(Product $product): void
    {
        $lots = InventoryLot::withoutGlobalScopes()->where('product_id', $product->id)->where('quantity', '>', 0)->whereNotNull('expiry_date');
        $next = (clone $lots)->whereDate('expiry_date', '>=', today())->orderBy('expiry_date')->first()
            ?? (clone $lots)->orderBy('expiry_date')->first();
        if ($next && (string) $product->expiry_date?->toDateString() !== $next->expiry_date->toDateString()) {
            $product->forceFill(['expiry_date' => $next->expiry_date])->save();
        }
    }

    private function branch(?int $branchId = null): Branch
    {
        if ($branchId !== null) {
            $branch = Branch::query()->where('is_active', true)->findOrFail($branchId);
            if ($this->context->resolved()) {
                if ((int) $branch->clinic_id !== (int) $this->context->clinicId()
                    || ! in_array((int) $branch->id, $this->context->authorizedBranchIds(), true)) {
                    throw new RuntimeException('The branch is not authorized in the active clinic context.');
                }
            }
            return $branch;
        }

        if ($this->context->resolved()) {
            return $this->context->requireBranch();
        }

        return Branch::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }
}
