<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lens cost prices were only set on a lens item's first receipt. Lenses now use the last
 * purchase cost, so bring each lens item up to its latest receipt that was not reversed.
 * Lens orders already placed keep the cost they recorded.
 */
return new class extends Migration {
    public function up(): void
    {
        $latestReceiptCost = 'SELECT m.unit_cost FROM optical_product_stock_movements m
            WHERE m.optical_product_id = optical_products.id AND m.movement_type = \'receipt\' AND m.unit_cost IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM optical_product_stock_movements r WHERE r.reverses_movement_id = m.id)';

        DB::statement("UPDATE optical_products SET cost_price = ($latestReceiptCost ORDER BY m.id DESC LIMIT 1)
            WHERE lens_specs IS NOT NULL AND EXISTS ($latestReceiptCost)");
    }

    public function down(): void
    {
        // Earlier first-receipt costs are not kept; the latest receipt cost remains.
    }
};
