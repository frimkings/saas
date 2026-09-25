<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Marks a frame/lens whose stock was already deducted by the POS sale,
        // so cancelling the order must not return it to stock a second time.
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->boolean('frame_stock_from_sale')->default(false)->after('stock_reserved_at');
            $table->boolean('lens_stock_from_sale')->default(false)->after('frame_stock_from_sale');
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn(['frame_stock_from_sale', 'lens_stock_from_sale']));
    }
};
