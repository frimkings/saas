<?php

namespace App\Services;

use App\Models\OpticalProduct;
use App\Models\OpticalPurchaseOrder;
use App\Models\OpticalSupplierReturn;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Damaged, wrong or excess stock sent back to a supplier: taken out of the branch ledger
 * against a return note, and followed until the supplier credits or replaces it.
 */
class OpticalSupplierReturnService
{
    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_cost?: float|string|null}>  $lines
     */
    public function create(int $supplierId, string $reason, array $lines, ?int $purchaseOrderId = null, ?string $notes = null): OpticalSupplierReturn
    {
        $this->assertManager();
        $supplier = Supplier::find($supplierId);
        if (! $supplier) throw ValidationException::withMessages(['returnSupplierId' => 'Choose the supplier the stock goes back to.']);
        if (! array_key_exists($reason, OpticalSupplierReturn::REASONS)) throw ValidationException::withMessages(['returnReason' => 'Choose a return reason.']);
        if ($lines === []) throw ValidationException::withMessages(['returnLines' => 'Add at least one item to return.']);
        $purchaseOrder = $purchaseOrderId ? OpticalPurchaseOrder::where('supplier_id', $supplier->id)->findOrFail($purchaseOrderId) : null;

        return DB::transaction(function () use ($supplier, $reason, $lines, $purchaseOrder, $notes) {
            $return = OpticalSupplierReturn::create([
                'return_number' => 'ORT-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'supplier_id' => $supplier->id, 'optical_purchase_order_id' => $purchaseOrder?->id,
                'reason' => $reason, 'notes' => $notes ? trim($notes) : null, 'created_by' => auth()->id(),
            ]);
            $credit = 0.0;
            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                $product = OpticalProduct::withTrashed()->findOrFail((int) ($line['product_id'] ?? 0));
                $cost = round(max(0, (float) (($line['unit_cost'] ?? '') === '' ? $product->cost_price : $line['unit_cost'])), 2);
                if ($quantity < 1) throw ValidationException::withMessages(['returnLines' => 'Each returned item needs a quantity of at least 1.']);
                try {
                    app(OpticalStockLedgerService::class)->returnToSupplier($product, $quantity, [
                        'unit_cost' => $cost, 'supplier' => $supplier->name,
                        'reference' => $return->return_number, 'optical_supplier_return_id' => $return->id,
                    ]);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['returnLines' => "Not enough {$product->name} in stock to return {$quantity}."]);
                }
                $return->lines()->create(['optical_product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost]);
                $credit += $quantity * $cost;
            }
            $return->update(['credit_expected' => round($credit, 2)]);
            return $return->load('lines');
        });
    }

    /** Record how the supplier settled the return. */
    public function settle(int $returnId, string $status, ?string $reference = null): OpticalSupplierReturn
    {
        $this->assertManager();
        if (! in_array($status, ['credited', 'replaced', 'written_off'], true)) throw ValidationException::withMessages(['settlement' => 'Choose how the return was settled.']);
        $return = OpticalSupplierReturn::findOrFail($returnId);
        if ($return->credit_status !== 'pending') throw ValidationException::withMessages(['settlement' => 'This return is already settled.']);
        $return->update(['credit_status' => $status, 'credit_reference' => $reference ? trim($reference) : null, 'settled_at' => now()]);
        return $return;
    }

    private function assertManager(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can return stock to a supplier.');
    }
}
