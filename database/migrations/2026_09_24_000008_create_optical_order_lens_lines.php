<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per eye. A stocked eye holds branch stock from placement and
        // is deducted when glazing starts; a special-order eye waits for the
        // supplier and blocks production until it is received.
        Schema::create('optical_order_lens_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('lens_order_id')->constrained('lens_orders')->cascadeOnDelete();
            $table->string('eye', 2);
            $table->string('source', 20);
            $table->foreignId('optical_product_id')->nullable()->constrained('optical_products')->nullOnDelete();
            $table->decimal('sphere', 5, 2);
            $table->decimal('power', 5, 2);
            $table->decimal('unit_price', 12, 2);
            $table->boolean('price_estimated')->default(false);
            $table->string('status', 20);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['lens_order_id', 'eye']);
            $table->index(['branch_id', 'optical_product_id', 'status'], 'optical_lens_lines_holds_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_order_lens_lines');
    }
};
