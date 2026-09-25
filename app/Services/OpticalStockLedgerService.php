<?php

namespace App\Services;

use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\OpticalProductStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpticalStockLedgerService
{
    public function receive(OpticalProduct $product, int $quantity, array $details): OpticalProductStockMovement
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity received must be greater than zero.']);
        }
        return $this->record($product, $quantity, 'receipt', 'Stock received', $details);
    }

    public function adjust(OpticalProduct $product, int $quantityChange, string $reason, ?string $notes = null): OpticalProductStockMovement
    {
        return $this->record($product, $quantityChange, 'adjustment', $reason, ['notes' => $notes]);
    }

    public function recordLensOrder(OpticalProduct $product, int $quantityChange, string $reference): OpticalProductStockMovement
    {
        return $this->record($product, $quantityChange, 'system', 'Lens order '.$reference, ['reference' => $reference]);
    }

    /** Stock returned to a supplier (negative) — linked to its return note. */
    public function returnToSupplier(OpticalProduct $product, int $quantity, array $details): OpticalProductStockMovement
    {
        if ($quantity <= 0) throw ValidationException::withMessages(['quantity' => 'Return quantity must be greater than zero.']);
        return $this->record($product, -$quantity, 'supplier_return', 'Returned to supplier', $details);
    }

    /** Correction from an approved stock count — linked to the count. */
    public function countCorrection(OpticalProduct $product, int $change, array $details): OpticalProductStockMovement
    {
        return $this->record($product, $change, 'count', 'Stock count correction', $details);
    }

    public function reverse(int $movementId): OpticalProductStockMovement
    {
        return DB::transaction(function () use ($movementId) {
            $original = OpticalProductStockMovement::query()->lockForUpdate()->findOrFail($movementId);
            if (! in_array($original->movement_type, ['receipt', 'adjustment'], true) || $original->reversedBy()->exists()) {
                throw ValidationException::withMessages(['movement' => 'This movement cannot be reversed again.']);
            }
            // Receipts against a supplier order stay in step with the order; correct them with a return or a count.
            if ($original->optical_purchase_order_line_id) {
                throw ValidationException::withMessages(['movement' => 'This receipt belongs to a supplier order. Record a supplier return or a stock count instead.']);
            }
            $product = OpticalProduct::withTrashed()->findOrFail($original->optical_product_id);
            $stock = OpticalProductStock::where('optical_product_id', $product->id)->lockForUpdate()->firstOrFail();
            $newBalance = $stock->quantity - $original->quantity_change;
            if ($newBalance < 0) {
                throw ValidationException::withMessages(['movement' => 'Cannot reverse this movement because the branch no longer has enough stock.']);
            }
            $stock->update(['quantity' => $newBalance]);
            return OpticalProductStockMovement::create([
                'clinic_id' => $original->clinic_id,
                'branch_id' => $original->branch_id,
                'optical_product_id' => $product->id,
                'user_id' => auth()->id(),
                'quantity_change' => -$original->quantity_change,
                'balance_after' => $newBalance,
                'reason' => 'Reversal of movement #'.$original->id,
                'movement_type' => 'reversal',
                'reference' => $original->reference,
                'supplier' => $original->supplier,
                'batch_number' => $original->batch_number,
                'unit_cost' => $original->unit_cost,
                'unit_price' => $original->unit_price,
                'reverses_movement_id' => $original->id,
            ]);
        });
    }

    private function record(OpticalProduct $product, int $change, string $type, string $reason, array $details): OpticalProductStockMovement
    {
        abort_unless((int) $product->clinic_id === (int) app(TenantContext::class)->clinicId(), 404);
        if ($change === 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity change cannot be zero.']);
        }
        return DB::transaction(function () use ($product, $change, $type, $reason, $details) {
            $stock = OpticalProductStock::where('optical_product_id', $product->id)->lockForUpdate()->first();
            if (! $stock) {
                $stock = OpticalProductStock::create([
                    'clinic_id' => $product->clinic_id,
                    'optical_product_id' => $product->id,
                    'quantity' => 0,
                    'reorder_level' => $product->lens_specs ? 10 : 5,
                ]);
            }
            $newBalance = $stock->quantity + $change;
            if ($newBalance < 0) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient stock at this branch for this adjustment.']);
            }
            $stock->update(['quantity' => $newBalance]);
            return OpticalProductStockMovement::create(array_merge([
                'clinic_id' => $product->clinic_id,
                'optical_product_id' => $product->id,
                'user_id' => auth()->id(),
                'quantity_change' => $change,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'movement_type' => $type,
            ], $details));
        });
    }
}
