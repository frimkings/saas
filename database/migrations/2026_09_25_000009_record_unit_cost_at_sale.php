<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost of sales uses what an item cost when it was sold, not today's cost price,
 * so past profit does not move when prices change. Existing rows are filled with
 * today's cost price, the best figure available for them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->decimal('unit_cost', 12, 2)->nullable()->after('selling_price'));
        Schema::table('optical_order_lens_lines', fn (Blueprint $table) => $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price'));
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->decimal('frame_unit_cost', 12, 2)->nullable();
            $table->decimal('lens_unit_cost', 12, 2)->nullable();
        });

        DB::statement('UPDATE sale_items SET unit_cost = (SELECT cost_price FROM products WHERE products.id = sale_items.product_id)
            WHERE unit_cost IS NULL AND product_id IS NOT NULL');
        DB::statement('UPDATE sale_items SET unit_cost = (SELECT cost_price FROM optical_products WHERE optical_products.id = sale_items.optical_product_id)
            WHERE unit_cost IS NULL AND optical_product_id IS NOT NULL');
        DB::statement('UPDATE optical_order_lens_lines SET unit_cost = (SELECT cost_price FROM optical_products WHERE optical_products.id = optical_order_lens_lines.optical_product_id)
            WHERE unit_cost IS NULL AND optical_product_id IS NOT NULL');
        DB::statement('UPDATE lens_orders SET frame_unit_cost = (SELECT cost_price FROM optical_products WHERE optical_products.id = lens_orders.frame_optical_product_id)
            WHERE frame_unit_cost IS NULL AND frame_optical_product_id IS NOT NULL');
        DB::statement('UPDATE lens_orders SET lens_unit_cost = (SELECT cost_price FROM optical_products WHERE optical_products.id = lens_orders.lens_optical_product_id)
            WHERE lens_unit_cost IS NULL AND lens_optical_product_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('optical_order_lens_lines', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn(['frame_unit_cost', 'lens_unit_cost']));
    }
};
