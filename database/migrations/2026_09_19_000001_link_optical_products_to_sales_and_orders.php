<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('optical_product_id')->nullable()->constrained('optical_products')->restrictOnDelete();
        });
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->foreignId('frame_optical_product_id')->nullable()->constrained('optical_products')->restrictOnDelete();
            $table->foreignId('lens_optical_product_id')->nullable()->constrained('optical_products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('frame_optical_product_id');
            $table->dropConstrainedForeignId('lens_optical_product_id');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('optical_product_id');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
