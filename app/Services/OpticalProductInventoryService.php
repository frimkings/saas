<?php

namespace App\Services;

use App\Models\OpticalOrderLensLine;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpticalProductInventoryService
{
    public function setBalance(OpticalProduct $product, int $quantity, int $reorderLevel, string $reason, string $movementType = 'system'): void
    {
        $context = app(TenantContext::class);
        abort_unless((int) $product->clinic_id === (int) $context->clinicId(), 404);
        DB::transaction(function () use ($product, $quantity, $reorderLevel, $reason, $movementType, $context) {
            $stock = OpticalProductStock::query()->where('optical_product_id', $product->id)->lockForUpdate()->first();
            if (! $stock) {
                $stock = OpticalProductStock::create([
                    'optical_product_id' => $product->id, 'quantity' => 0, 'reorder_level' => $reorderLevel,
                ]);
            }
            $delta = $quantity - $stock->quantity;
            $stock->update(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
            if ($delta !== 0) {
                DB::table('optical_product_stock_movements')->insert([
                    'clinic_id' => $context->clinicId(), 'branch_id' => $context->branchId(),
                    'optical_product_id' => $product->id, 'user_id' => auth()->id(),
                    'quantity_change' => $delta, 'balance_after' => $quantity, 'reason' => $reason,
                    'movement_type' => $movementType,
                    'unit_cost' => $product->cost_price,
                    'unit_price' => $product->selling_price,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Stock lenses held for customer orders stay on the shelf until glazing starts, but they
     * are spoken for: only the rest can be sold or reserved.
     */
    public function heldForOrders(OpticalProduct $product): int
    {
        return OpticalOrderLensLine::where('optical_product_id', $product->id)->where('source', 'stock')
            ->whereIn('status', OpticalOrderLensLine::HOLDING)->count();
    }

    /** What can still be sold or reserved: the shelf quantity less lenses held for orders. */
    public function available(OpticalProduct $product): int
    {
        $shelf = (int) OpticalProductStock::query()->where('optical_product_id', $product->id)->value('quantity');

        return max(0, $shelf - $this->heldForOrders($product));
    }

    public function decrease(OpticalProduct $product, int $quantity, string $reason, string $movementType = 'system'): void
    {
        DB::transaction(function () use ($product, $quantity, $reason, $movementType) {
            $stock = OpticalProductStock::query()->where('optical_product_id', $product->id)->lockForUpdate()->first();
            if ($quantity < 1 || ! $stock || $stock->quantity - $this->heldForOrders($product) < $quantity) {
                throw ValidationException::withMessages(['stock' => "Insufficient stock for {$product->name} at this branch."]);
            }
            $this->setBalance($product, $stock->quantity - $quantity, $stock->reorder_level, $reason, $movementType);
        });
    }

    public function increase(OpticalProduct $product, int $quantity, string $reason, string $movementType = 'system'): void
    {
        if ($quantity < 1) return;
        DB::transaction(function () use ($product, $quantity, $reason, $movementType) {
            $stock = OpticalProductStock::query()->where('optical_product_id', $product->id)->lockForUpdate()->first();
            $this->setBalance($product, ($stock?->quantity ?? 0) + $quantity, $stock?->reorder_level ?? 5, $reason, $movementType);
        });
    }
}
