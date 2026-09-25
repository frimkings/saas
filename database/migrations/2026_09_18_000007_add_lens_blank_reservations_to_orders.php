<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->string('lens_supply_source', 20)->default('outside');
            $table->string('stock_lens_index', 20)->nullable();
            $table->string('stock_lens_coating', 80)->nullable();
            $table->json('lens_blank_allocations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropColumn(['lens_supply_source', 'stock_lens_index', 'stock_lens_coating', 'lens_blank_allocations']);
        });
    }
};
