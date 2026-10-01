<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Items reception added to a prescription at the POS (e.g. a frame) were sold, but their
 * cart row stayed "pending", so the doctor's Prescription tab still listed them as unsent
 * and could send them to the cashier again. The POS now marks those rows; this settles the
 * ones already left behind, each against the sale it was actually sold in.
 *
 * A pending row is settled only by a clinical sale of the same product, to the same patient
 * (and consultation, when both have one), on a sale line added after the row, that is
 * not tied to any cart row yet. Each sale line settles at most one row. Stock and money are
 * untouched: the sale already recorded both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pending = DB::table('carts')
            ->where('purchased', false)
            ->when(DB::getSchemaBuilder()->hasColumn('carts', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('id')
            ->get(['id', 'patient_id', 'product_id', 'consultation_id', 'created_at']);

        foreach ($pending as $cart) {
            $line = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->whereNull('sale_items.cart_id')
                ->where('sale_items.product_id', $cart->product_id)
                ->where('sales.patient_id', $cart->patient_id)
                ->where('sales.business_line', 'clinic')
                ->where('sales.is_refunded', false)
                ->whereNull('sales.deleted_at')
                ->where('sale_items.created_at', '>=', $cart->created_at)
                ->when($cart->consultation_id, fn ($q) => $q->where(fn ($match) => $match
                    ->where('sales.consultation_id', $cart->consultation_id)
                    ->orWhereNull('sales.consultation_id')))
                ->orderBy('sale_items.created_at')
                ->first(['sale_items.id', 'sale_items.dispensed_quantity', 'sale_items.created_at']);

            if (!$line) {
                continue;
            }

            DB::table('sale_items')->where('id', $line->id)->update(['cart_id' => $cart->id]);
            DB::table('carts')->where('id', $cart->id)->update([
                'purchased'    => true,
                'status'       => 'completed',
                'is_dispensed' => (int) $line->dispensed_quantity > 0,
                'dispensed_at' => (int) $line->dispensed_quantity > 0 ? $line->created_at : null,
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        // A data repair: the rows it settled were genuinely sold, so there is nothing to undo.
    }
};
