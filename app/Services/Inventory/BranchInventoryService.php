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

    public function increase(Product|int $product, int $quantity, array $lot = [], ?int $branchId = null): BranchInventoryItem
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Inventory quantity must be greater than zero.');
        }

        return $this->adjust($product, $quantity, $branchId, $lot);
    }

    public function decrease(Product|int $product, int $quantity, ?int $branchId = null): BranchInventoryItem
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Inventory quantity must be greater than zero.');
        }

        return $this->adjust($product, -$quantity, $branchId);
    }

    private function adjust(Product|int $product, int $delta, ?int $branchId, array $lot = []): BranchInventoryItem
    {
        $productId = $product instanceof Product ? (int) $product->getKey() : $product;
        $branch = $this->branch($branchId);

        return DB::transaction(function () use ($productId, $branch, $delta, $lot) {
            $product = Product::withoutGlobalScopes()->lockForUpdate()->findOrFail($productId);
            if ((int) $product->clinic_id !== (int) $branch->clinic_id) {
                throw new RuntimeException('The product and branch belong to different clinics.');
            }

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
            $next = (int) $inventory->quantity + $delta;
            if ($next < 0) {
                throw new RuntimeException('Insufficient stock for '.$product->name.' at '.$branch->name.'.');
            }

            $inventory->forceFill(['quantity' => $next, 'version' => (int) $inventory->version + 1])->save();

            // The legacy aggregate remains synchronized during the staged rollout.
            $product->forceFill([
                'quantity' => (int) BranchInventoryItem::withoutGlobalScopes()
                    ->where('product_id', $productId)->sum('quantity'),
            ])->save();

            if ($delta > 0 && ($lot['batch_number'] ?? $lot['expiry_date'] ?? null)) {
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

            return $inventory->fresh();
        });
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
